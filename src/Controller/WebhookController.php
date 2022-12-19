<?php

declare(strict_types=1);

namespace PHPCensor\Controller;

use Exception;
use GuzzleHttp\Client;
use PHPCensor\BuildFactory;
use PHPCensor\Common\Application\ConfigurationInterface;
use PHPCensor\Controller;
use PHPCensor\Exception\HttpException\ForbiddenException;
use PHPCensor\Exception\HttpException\NotFoundException;
use PHPCensor\Common\Exception\InvalidArgumentException;
use PHPCensor\Common\Exception\RuntimeException;
use PHPCensor\Helper\Lang;
use PHPCensor\Store\BuildErrorStore;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use PHPCensor\Model\Build;
use PHPCensor\Model\Build\BitbucketBuild;
use PHPCensor\Model\Build\BitbucketServerBuild;
use PHPCensor\Model\Build\GithubBuild;
use PHPCensor\Model\Project;
use PHPCensor\Model\WebhookRequest;
use PHPCensor\Service\BuildService;
use PHPCensor\Store\BuildStore;
use PHPCensor\Store\EnvironmentStore;
use PHPCensor\Store\ProjectStore;
use PHPCensor\Store\WebhookRequestStore;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * @package    PHP Censor
 * @subpackage Application
 *
 * @author Dan Cryer <dan@block8.co.uk>
 * @author Sami Tikka <stikka@iki.fi>
 * @author Alex Russell <alex@clevercherry.com>
 * @author Guillaume Perréal <adirelle@gmail.com>
 * @author Dmitry Khomutov <poisoncorpsee@gmail.com>
 */
class WebhookController extends Controller
{
    protected BuildService $buildService;

    protected BuildFactory $buildFactory;

    private bool $logRequests = false;

    protected ProjectStore $projectStore;
    protected BuildStore $buildStore;
    protected BuildErrorStore $buildErrorStore;
    protected EnvironmentStore $environmentStore;
    protected WebhookRequestStore $webhookRequestStore;

    public function __construct(
        ConfigurationInterface $configuration,
        Request $request,
        Session $session,
        ProjectStore $projectStore,
        BuildStore $buildStore,
        BuildErrorStore $buildErrorStore,
        EnvironmentStore $environmentStore,
        WebhookRequestStore $webhookRequestStore
    ) {
        parent::__construct($configuration, $request, $session);

        $this->projectStore = $projectStore;
        $this->buildStore = $buildStore;
        $this->buildErrorStore = $buildErrorStore;
        $this->environmentStore = $environmentStore;
        $this->webhookRequestStore = $webhookRequestStore;
    }

    /**
     * Initialise the controller, set up stores and services.
     */
    public function init(): void
    {
        $this->buildFactory = new BuildFactory(
            $this->configuration,
            $this->buildStore
        );

        $this->buildService = new BuildService(
            $this->configuration,
            $this->buildFactory,
            $this->buildStore,
            $this->buildErrorStore,
            $this->projectStore
        );

        $this->logRequests = (bool)$this->configuration->get('php-censor.webhook.log_requests', false);
    }

    /**
     * Handle the action, Ensuring to return a JsonResponse.
     */
    public function handleAction(string $action, array $actionParams): Response
    {
        $response = new JsonResponse();
        try {
            $data = parent::handleAction($action, $actionParams);
            if (isset($data['responseCode'])) {
                $response->setStatusCode($data['responseCode']);
                unset($data['responseCode']);
            }
            $response->setData($data);
        } catch (\Throwable $ex) {
            $response->setStatusCode(500);
            $response->setData(['status' => 'failed', 'error' => $ex->getMessage()]);
        }

        return $response;
    }

    /**
     * Wrapper for creating a new build.
     *
     * @return string[]
     *
     * @throws NotFoundException
     * @throws RuntimeException
     * @throws \PHPCensor\Exception\HttpException
     */
    protected function createBuild(
        int $source,
        Project $project,
        string $commitId,
        string $branch,
        ?string $tag,
        string $committer,
        string $commitMessage,
        ?array $extra = null,
        ?string $environment = null
    ): array {
        if ($project->getArchived()) {
            throw new NotFoundException(Lang::get('project_x_not_found', $project->getId()));
        }

        // Check if a build already exists for this commit ID:
        $builds = $this->buildStore->getByProjectAndCommit($project->getId(), $commitId);

        $ignoreEnvironments = [];
        $ignoreTags         = [];
        if ($builds['count']) {
            foreach ($builds['items'] as $build) {
                /** @var Build $build */
                $ignoreEnvironments[$build->getId()] = $build->getEnvironmentId();
                $ignoreTags[$build->getId()]         = $build->getTag();
            }
        }

        // Check if this branch is to be built.
        if ($project->getDefaultBranchOnly() && ($branch !== $project->getDefaultBranch())) {
            return [
                'status'  => 'ignored',
                'message' => 'The branch is not a branch by default. Build is allowed only for the branch by default.'
            ];
        }

        $environments = $project->getEnvironmentsObjects();
        if ($environments['count']) {
            $createdBuilds = [];
            $environmentObject = $this->environmentStore->getByNameAndProjectId($environment, $project->getId());
            if ($environment && $environmentObject) {
                if (
                    !\in_array($environmentObject->getId(), $ignoreEnvironments, true) ||
                    ($tag && !\in_array($tag, $ignoreTags, true))
                ) {
                    // If not, create a new build job for it:
                    $build = $this->buildService->createBuild(
                        $project,
                        $environmentObject->getId(),
                        $commitId,
                        $project->getDefaultBranch(),
                        $tag,
                        $committer,
                        $commitMessage,
                        (int)$source,
                        null,
                        $extra
                    );

                    $createdBuilds[] = [
                        'id'          => $build->getID(),
                        'environment' => $environmentObject->getId(),
                    ];
                } else {
                    $duplicates[] = \array_search($environmentObject->getId(), $ignoreEnvironments, true);
                }

                if (!empty($createdBuilds)) {
                    if (empty($duplicates)) {
                        return ['status' => 'ok', 'builds' => $createdBuilds];
                    } else {
                        return [
                            'status'  => 'ok',
                            'builds'  => $createdBuilds,
                            'message' => \sprintf(
                                'For this commit some builds already exists (%s)',
                                \implode(', ', $duplicates)
                            )
                        ];
                    }
                } else {
                    return [
                        'status'  => 'ignored',
                        'message' => \sprintf(
                            'For this commit already created builds (%s)',
                            \implode(', ', $duplicates)
                        )
                    ];
                }
            } else {
                $environmentIds = $project->getEnvironmentsNamesByBranch($branch);
                // use base branch from project
                if (!empty($environmentIds)) {
                    $duplicates = [];
                    foreach ($environmentIds as $environmentId) {
                        if (
                            !\in_array($environmentId, $ignoreEnvironments, true) ||
                            ($tag && !\in_array($tag, $ignoreTags, true))
                        ) {
                            // If not, create a new build job for it:
                            $build = $this->buildService->createBuild(
                                $project,
                                (int)$environmentId,
                                $commitId,
                                $project->getDefaultBranch(),
                                $tag,
                                $committer,
                                $commitMessage,
                                $source,
                                null,
                                $extra
                            );

                            $createdBuilds[] = [
                                'id'          => $build->getID(),
                                'environment' => $environmentId,
                            ];
                        } else {
                            $duplicates[] = \array_search($environmentId, $ignoreEnvironments, true);
                        }
                    }

                    if (!empty($createdBuilds)) {
                        if (empty($duplicates)) {
                            return ['status' => 'ok', 'builds' => $createdBuilds];
                        } else {
                            return [
                                'status'  => 'ok',
                                'builds'  => $createdBuilds,
                                'message' => \sprintf(
                                    'For this commit some builds already exists (%s)',
                                    \implode(', ', $duplicates)
                                )
                            ];
                        }
                    } else {
                        return [
                            'status'  => 'ignored',
                            'message' => \sprintf(
                                'For this commit already created builds (%s)',
                                \implode(', ', $duplicates)
                            )
                        ];
                    }
                } else {
                    return ['status' => 'ignored', 'message' => 'Branch not assigned to any environment'];
                }
            }
        } else {
            $environmentId = null;
            if (!\in_array($environmentId, $ignoreEnvironments, true) ||
                ($tag && !\in_array($tag, $ignoreTags, true))) {
                $build = $this->buildService->createBuild(
                    $project,
                    null,
                    $commitId,
                    $branch,
                    $tag,
                    $committer,
                    $commitMessage,
                    (int)$source,
                    null,
                    $extra
                );

                return ['status' => 'ok', 'buildID' => $build->getID()];
            } else {
                return [
                    'status'  => 'ignored',
                    'message' => \sprintf(
                        'Duplicate of build #%d',
                        \array_search($environmentId, $ignoreEnvironments, true)
                    ),
                ];
            }
        }
    }

    /**
     * Fetch a project and check its type.
     *
     * @throws Exception If the project does not exist or is not of the expected type.
     */
    protected function fetchProject(int $projectId, array $expectedType): Project
    {
        if (!$projectId) {
            throw new NotFoundException('Project does not exist: ' . $projectId);
        }

        /** @var Project $project */
        $project = $this->projectStore->getById($projectId);

        if (!\in_array($project->getType(), $expectedType, true)) {
            throw new NotFoundException('Wrong project type: ' . $project->getType());
        }

        return $project;
    }

    protected function logWebhookRequest(
        int $projectId,
        string $webhookType,
        string $payload
    ): void {
        try {
            if ($this->logRequests) {
                $webhookRequest = new WebhookRequest();

                $webhookRequest->setProjectId($projectId);
                $webhookRequest->setWebhookType($webhookType);
                $webhookRequest->setPayload($payload);
                $webhookRequest->setCreateDate(new \DateTime());

                $this->webhookRequestStore->save($webhookRequest);
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Called by POSTing to /webhook/git/<project_id>?branch=<branch>&commit=<commit>
     *
     * @throws Exception
     */
    public function git(int $projectId): array
    {
        $project = $this->fetchProject($projectId, [
            Project::TYPE_LOCAL,
            Project::TYPE_GIT,
            Project::TYPE_GITHUB,
        ]);

        $payload = [
            'branch'         => $this->getParam('branch', $project->getDefaultBranch()),
            'environment'    => $this->getParam('environment'),
            'commit'         => (string)$this->getParam('commit', ''),
            'commit_message' => (string)$this->getParam('message', ''),
            'committer'      => (string)$this->getParam('committer', ''),
        ];
        $payloadJson = \json_encode($payload);

        $this->logWebhookRequest(
            $project->getId(),
            WebhookRequest::WEBHOOK_TYPE_GIT,
            $payloadJson
        );

        return $this->createBuild(
            Build::SOURCE_WEBHOOK_PUSH,
            $project,
            $payload['commit'],
            $payload['branch'],
            null,
            $payload['committer'],
            $payload['commit_message'],
            null,
            $payload['environment']
        );
    }

    /**
     * Called by POSTing to /webhook/hg/<project_id>?branch=<branch>&commit=<commit>
     *
     * @throws Exception
     */
    public function hg(int $projectId): array
    {
        $project = $this->fetchProject($projectId, [
            Project::TYPE_LOCAL,
            Project::TYPE_HG,
        ]);

        $payload = [
            'branch'         => $this->getParam('branch', $project->getDefaultBranch()),
            'environment'    => $this->getParam('environment'),
            'commit'         => (string)$this->getParam('commit', ''),
            'commit_message' => (string)$this->getParam('message', ''),
            'committer'      => (string)$this->getParam('committer', ''),
        ];
        $payloadJson = \json_encode($payload);

        $this->logWebhookRequest(
            $project->getId(),
            WebhookRequest::WEBHOOK_TYPE_HG,
            $payloadJson
        );

        return $this->createBuild(
            Build::SOURCE_WEBHOOK_PUSH,
            $project,
            $payload['commit'],
            $payload['branch'],
            null,
            $payload['committer'],
            $payload['commit_message'],
            null,
            $payload['environment']
        );
    }

    /**
     * Called by POSTing to /webhook/svn/<project_id>?branch=<branch>&commit=<commit>
     *
     * @author Sylvain Lévesque <slevesque@gezere.com>
     *
     * @throws Exception
     */
    public function svn(int $projectId): array
    {
        $project = $this->fetchProject($projectId, [
            Project::TYPE_SVN
        ]);

        $payload = [
            'branch'         => $this->getParam('branch', $project->getDefaultBranch()),
            'environment'    => $this->getParam('environment'),
            'commit'         => (string)$this->getParam('commit', ''),
            'commit_message' => (string)$this->getParam('message', ''),
            'committer'      => (string)$this->getParam('committer', ''),
        ];
        $payloadJson = \json_encode($payload);

        $this->logWebhookRequest(
            $project->getId(),
            WebhookRequest::WEBHOOK_TYPE_SVN,
            $payloadJson
        );

        return $this->createBuild(
            Build::SOURCE_WEBHOOK_PUSH,
            $project,
            $payload['commit'],
            $payload['branch'],
            null,
            $payload['committer'],
            $payload['commit_message'],
            null,
            $payload['environment']
        );
    }

    /**
     * Called by Bitbucket.
     *
     * @throws Exception
     */
    public function bitbucket(int $projectId): array
    {
        $project = $this->fetchProject($projectId, [
            Project::TYPE_BITBUCKET,
            Project::TYPE_BITBUCKET_HG,
            Project::TYPE_BITBUCKET_SERVER,
            Project::TYPE_GIT,
            Project::TYPE_HG,
        ]);

        // Support both old services and new webhooks
        if ($payloadJson = $this->getParam('payload')) {
            $this->logWebhookRequest(
                $project->getId(),
                WebhookRequest::WEBHOOK_TYPE_BITBUCKET,
                $payloadJson
            );

            return $this->bitbucketService(\json_decode($payloadJson, true), $project);
        }

        $payloadJson = \file_get_contents("php://input");
        $payload     = \json_decode($payloadJson, true);

        if ($payloadJson) {
            $this->logWebhookRequest(
                $project->getId(),
                WebhookRequest::WEBHOOK_TYPE_BITBUCKET,
                $payloadJson
            );
        }

        // Handle Pull Request webhooks:
        if (!empty($payload['pullrequest'])) {
            return $this->bitbucketPullRequest($project, $payload);
        }

        // Handle Pull Request webhook for BB server:
        if (!empty($payload['pullRequest'])) {
            return $this->bitbucketSvrPullRequest($project, $payload);
        }

        // Handle Push (and Tag) webhooks:
        if (!empty($payload['push']['changes'])) {
            return $this->bitbucketCommitRequest($project, $payload);
        }

        // Invalid event from bitbucket
        return [
            'status' => 'failed',
            'commits' => []
        ];
    }

    /**
     * Handle the payload when Bitbucket sends a commit webhook.
     */
    protected function bitbucketCommitRequest(Project $project, array $payload): array
    {
        $results = [];
        $status  = 'failed';
        foreach ($payload['push']['changes'] as $commit) {
            if (!empty($commit['new'])) {
                try {
                    $email = $commit['new']['target']['author']['raw'];
                    if (\strpos($email, '>') !== false) {
                        // In order not to lose email if it is RAW, w/o "<>" symbols
                        $email = \substr($email, 0, \strpos($email, '>'));
                        $email = \substr($email, \strpos($email, '<') + 1);
                    }

                    $results[$commit['new']['target']['hash']] = $this->createBuild(
                        Build::SOURCE_WEBHOOK_PUSH,
                        $project,
                        $commit['new']['target']['hash'],
                        $commit['new']['name'],
                        null,
                        $email,
                        $commit['new']['target']['message']
                    );
                    $status = 'ok';
                } catch (\Throwable $ex) {
                    $results[$commit['new']['target']['hash']] = ['status' => 'failed', 'error' => $ex->getMessage()];
                }
            }
        }

        return ['status' => $status, 'commits' => $results];
    }

    /**
     * Handle the payload when Bitbucket sends a Pull Request webhook.
     *
     * @throws Exception
     */
    protected function bitbucketPullRequest(Project $project, array $payload): array
    {
        $triggerType = \trim($_SERVER['HTTP_X_EVENT_KEY']);

        if (!\array_key_exists(
            $triggerType,
            BitbucketBuild::$pullrequestTriggersToSources
        )) {
            return [
                'status'  => 'ignored',
                'message' => 'Trigger type "' . $triggerType . '" is not supported.'
            ];
        }

        $username    = $this->configuration->get('php-censor.bitbucket.username');
        $appPassword = $this->configuration->get('php-censor.bitbucket.app_password');

        if (empty($username) || empty($appPassword)) {
            throw new ForbiddenException('Please provide Username and App Password of your Bitbucket account.');
        }

        $commitsUrl = $payload['pullrequest']['links']['commits']['href'];

        $client = new Client();
        $commitsResponse = $client->get($commitsUrl, [
            'auth' => [$username, $appPassword],
        ]);
        $httpStatus = (int)$commitsResponse->getStatusCode();

        // Check we got a success response:
        if ($httpStatus < 200 || $httpStatus >= 300) {
            throw new RuntimeException('Could not get commits, failed API request.');
        }

        $results = [];
        $status  = 'failed';
        $commits = \json_decode((string)$commitsResponse->getBody(), true)['values'];
        foreach ($commits as $commit) {
            // Skip all but the current HEAD commit ID:
            $id = $commit['hash'];
            if (\strpos($id, $payload['pullrequest']['source']['commit']['hash']) !== 0) {
                $results[$id] = ['status' => 'ignored', 'message' => 'not branch head'];

                continue;
            }

            try {
                $branch    = $payload['pullrequest']['destination']['branch']['name'];
                $committer = $commit['author']['raw'];
                if (\strpos($committer, '>') !== false) {
                    // In order not to lose email if it is RAW, w/o "<>" symbols
                    $committer = \substr($committer, 0, \strpos($committer, '>'));
                    $committer = \substr($committer, \strpos($committer, '<') + 1);
                }
                $message   = $commit['message'];

                $extra = [
                    'pull_request_number' => $payload['pullrequest']['id'],
                    'remote_branch'       => $payload['pullrequest']['source']['branch']['name'],
                    'remote_reference'    => $payload['pullrequest']['source']['repository']['full_name'],
                ];

                $results[$id] = $this->createBuild(
                    BitbucketBuild::$pullrequestTriggersToSources[$triggerType],
                    $project,
                    $id,
                    $branch,
                    null,
                    $committer,
                    $message,
                    $extra
                );
                $status = 'ok';
            } catch (\Throwable $ex) {
                $results[$id] = ['status' => 'failed', 'error' => $ex->getMessage()];
            }
        }

        return ['status' => $status, 'commits' => $results];
    }

    /**
     * Handle the payload when Bitbucket Server sends a Pull Request webhook.
     *
     * @throws Exception
     */
    protected function bitbucketSvrPullRequest(Project $project, array $payload): array
    {
        $triggerType = \trim($_SERVER['HTTP_X_EVENT_KEY']);

        if (!\array_key_exists(
            $triggerType,
            BitbucketServerBuild::$pullrequestTriggersToSources
        )) {
            return [
                'status'  => 'ignored',
                'message' => 'Trigger type "' . $triggerType . '" is not supported.'
            ];
        }

        try {
            $branch    = $payload['pullRequest']['toRef']['displayId'];
            $committer = $payload['pullRequest']['author']['user']['emailAddress'];
            $message   = $payload['pullRequest']['description'];
            $id        = $payload['pullRequest']['fromRef']['latestCommit'];

            $extra = [
                'pull_request_number' => $payload['pullRequest']['id'],
                'remote_branch'       => $payload['pullrequest']['fromRef']['displayId'],
                'remote_reference'    => $payload['pullrequest']['fromRef']['repository']['project']['name'],
            ];

            $results = [];

            $results[$id] = $this->createBuild(
                BitbucketServerBuild::$pullrequestTriggersToSources[$triggerType],
                $project,
                $id,
                $branch,
                null,
                $committer,
                $message,
                $extra
            );
            $status = 'ok';
        } catch (\Throwable $ex) {
            $results[$id] = ['status' => 'failed', 'error' => $ex->getMessage()];
        }

        return ['status' => $status, 'commits' => $results];
    }

    /**
     * Bitbucket POST service.
     */
    protected function bitbucketService(array $payload, Project $project): array
    {
        $results = [];
        $status  = 'failed';
        foreach ($payload['commits'] as $commit) {
            try {
                $email = $commit['raw_author'];
                $email = \substr($email, 0, \strpos($email, '>'));
                $email = \substr($email, \strpos($email, '<') + 1);

                $results[$commit['raw_node']] = $this->createBuild(
                    Build::SOURCE_WEBHOOK_PUSH,
                    $project,
                    $commit['raw_node'],
                    $commit['branch'],
                    null,
                    $email,
                    $commit['message']
                );
                $status = 'ok';
            } catch (\Throwable $ex) {
                $results[$commit['raw_node']] = ['status' => 'failed', 'error' => $ex->getMessage()];
            }
        }

        return ['status' => $status, 'commits' => $results];
    }

    /**
     * @throws Exception
     */
    public function github(int $projectId): array
    {
        $project = $this->fetchProject($projectId, [
            Project::TYPE_GITHUB,
            Project::TYPE_GIT,
        ]);

        switch ($_SERVER['CONTENT_TYPE']) {
            case 'application/json':
                $payloadJson = \file_get_contents('php://input');

                break;
            case 'application/x-www-form-urlencoded':
                $payloadJson = $this->getParam('payload');

                break;
            default:
                return [
                    'status'       => 'failed',
                    'error'        => 'Content type not supported.',
                    'responseCode' => 401
                ];
        }

        if ($payloadJson) {
            $this->logWebhookRequest(
                $project->getId(),
                WebhookRequest::WEBHOOK_TYPE_GITHUB,
                $payloadJson
            );
        }

        $payload = \json_decode($payloadJson, true);

        // Handle Pull Request webhooks:
        if (\array_key_exists('pull_request', $payload)) {
            return $this->githubPullRequest($project, $payload);
        }

        // Handle Push (and Tag) webhooks:
        if (\array_key_exists('head_commit', $payload)) {
            return $this->githubCommitRequest($project, $payload);
        }

        return ['status' => 'ignored', 'message' => 'Unusable payload.'];
    }

    /**
     * Handle the payload when Github sends a commit webhook.
     */
    protected function githubCommitRequest(Project $project, array $payload): array
    {
        // Github sends a payload when you close a pull request with a non-existent commit. We don't want this.
        if (\array_key_exists('after', $payload) &&
            $payload['after'] === '0000000000000000000000000000000000000000') {
            return ['status' => 'ignored'];
        }

        if (isset($payload['head_commit']) && $payload['head_commit']) {
            $isTag   = (\substr($payload['ref'], 0, 10) === 'refs/tags/') ? true : false;
            $commit  = $payload['head_commit'];
            $results = [];
            $status  = 'failed';

            if (!$commit['distinct']) {
                $results[$commit['id']] = ['status' => 'ignored'];
            } else {
                try {
                    $tag = null;
                    if ($isTag) {
                        $tag       = \str_replace('refs/tags/', '', $payload['ref']);
                        $branch    = \str_replace('refs/heads/', '', $payload['base_ref']);
                        $committer = $payload['pusher']['email'];
                    } else {
                        $branch    = \str_replace('refs/heads/', '', $payload['ref']);
                        $committer = $commit['committer']['email'];
                    }

                    $results[$commit['id']] = $this->createBuild(
                        Build::SOURCE_WEBHOOK_PUSH,
                        $project,
                        $commit['id'],
                        $branch,
                        $tag,
                        $committer,
                        $commit['message']
                    );

                    $status = 'ok';
                } catch (\Throwable $ex) {
                    $results[$commit['id']] = ['status' => 'failed', 'error' => $ex->getMessage()];
                }
            }

            return ['status' => $status, 'commits' => $results];
        }

        return ['status' => 'ignored', 'message' => 'Unusable payload.'];
    }

    /**
     * Handle the payload when Github sends a Pull Request webhook.
     *
     * @throws Exception
     */
    protected function githubPullRequest(Project $project, array $payload): array
    {
        $triggerType = \trim($payload['action']);

        if (!\array_key_exists(
            $triggerType,
            GithubBuild::$pullrequestTriggersToSources
        )) {
            return [
                'status'  => 'ignored',
                'message' => 'Trigger type "' . $triggerType . '" is not supported.'
            ];
        }

        $headers = [];
        $token   = $this->configuration->get('php-censor.github.token');

        if (!empty($token)) {
            $headers['Authorization'] = 'token ' . $token;
        }

        $url = $payload['pull_request']['commits_url'];

        //for large pull requests, allow grabbing more then the default number of commits
        $customPerPage = $this->configuration->get('php-censor.github.per_page');
        $params        = [];
        if ($customPerPage) {
            $params['per_page'] = $customPerPage;
        }

        $client   = new Client();
        $response = $client->get($url, [
            'headers' => $headers,
            'query'   => $params,
        ]);
        $status = $response->getStatusCode();

        // Check we got a success response:
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Could not get commits, failed API request.');
        }

        $results = [];
        $status  = 'failed';
        $commits = \json_decode((string)$response->getBody(), true);
        foreach ($commits as $commit) {
            // Skip all but the current HEAD commit ID:
            $id = $commit['sha'];
            if ($id !== $payload['pull_request']['head']['sha']) {
                $results[$id] = ['status' => 'ignored', 'message' => 'not branch head'];

                continue;
            }

            try {
                $branch    = \str_replace('refs/heads/', '', $payload['pull_request']['base']['ref']);
                $committer = $commit['commit']['author']['email'];
                $message   = $commit['commit']['message'];

                $extra = [
                    'pull_request_number' => $payload['number'],
                    'remote_branch'       => $payload['pull_request']['head']['ref'],
                    'remote_reference'    => $payload['pull_request']['head']['repo']['full_name'],
                ];

                $results[$id] = $this->createBuild(
                    GithubBuild::$pullrequestTriggersToSources[$triggerType],
                    $project,
                    $id,
                    $branch,
                    null,
                    $committer,
                    $message,
                    $extra
                );
                $status = 'ok';
            } catch (\Throwable $ex) {
                $results[$id] = ['status' => 'failed', 'error' => $ex->getMessage()];
            }
        }

        return ['status' => $status, 'commits' => $results];
    }

    /**
     * Called by Gitlab Webhooks:
     *
     * @throws Exception
     */
    public function gitlab(int $projectId): array
    {
        $project = $this->fetchProject($projectId, [
            Project::TYPE_GITLAB,
            Project::TYPE_GIT,
        ]);

        $payloadJson = \file_get_contents("php://input");

        if ($payloadJson) {
            $this->logWebhookRequest(
                $project->getId(),
                WebhookRequest::WEBHOOK_TYPE_GITLAB,
                $payloadJson
            );
        }

        $payload = \json_decode($payloadJson, true);

        // build on merge request events
        if (isset($payload['object_kind']) && $payload['object_kind'] === 'merge_request') {
            $attributes = $payload['object_attributes'];
            if ($attributes['state'] === 'opened' || $attributes['state'] === 'reopened') {
                $branch    = $attributes['source_branch'];
                $commit    = $attributes['last_commit'];
                $committer = $commit['author']['email'];

                return $this->createBuild(
                    Build::SOURCE_WEBHOOK_PULL_REQUEST_CREATED,
                    $project,
                    $commit['id'],
                    $branch,
                    null,
                    $committer,
                    $commit['message']
                );
            }
        }

        // build on push events
        if (isset($payload['commits']) && \is_array($payload['commits'])) {
            // If we have a list of commits, then add them all as builds to be tested:

            $results = [];
            $status  = 'failed';

            $commit = \end($payload['commits']);
            try {
                $branch                 = \str_replace('refs/heads/', '', $payload['ref']);
                $committer              = $commit['author']['email'];
                $results[$commit['id']] = $this->createBuild(
                    Build::SOURCE_WEBHOOK_PUSH,
                    $project,
                    $commit['id'],
                    $branch,
                    null,
                    $committer,
                    $commit['message']
                );
                $status = 'ok';
            } catch (\Throwable $ex) {
                $results[$commit['id']] = ['status' => 'failed', 'error' => $ex->getMessage()];
            }

            return ['status' => $status, 'commits' => $results];
        }

        return ['status' => 'ignored', 'message' => 'Unusable payload.'];
    }

    /**
     * @param string $projectId
     *
     * @throws Exception
     */
    public function gogs(int $projectId): array
    {
        $project = $this->fetchProject($projectId, [
            Project::TYPE_GOGS,
            Project::TYPE_GIT,
        ]);

        $contentType = !empty($_SERVER['CONTENT_TYPE'])
            ? $_SERVER['CONTENT_TYPE']
            : null;

        switch ($contentType) {
            case 'application/x-www-form-urlencoded':
                $payloadJson = $this->getParam('payload');

                break;
            case 'application/json':
            default:
                $payloadJson = \file_get_contents('php://input');
        }

        if ($payloadJson) {
            $this->logWebhookRequest(
                $project->getId(),
                WebhookRequest::WEBHOOK_TYPE_GOGS,
                $payloadJson
            );
        }

        $payload = \json_decode($payloadJson, true);

        // Handle Push web hooks:
        if (\array_key_exists('commits', $payload)) {
            return $this->gogsCommitRequest($project, $payload);
        }

        if (\array_key_exists('pull_request', $payload)) {
            return $this->gogsPullRequest($project, $payload);
        }

        return ['status' => 'ignored', 'message' => 'Unusable payload.'];
    }

    /**
     * Handle the payload when Gogs sends a commit webhook.
     */
    protected function gogsCommitRequest(Project $project, array $payload): array
    {
        if (isset($payload['commits']) && \is_array($payload['commits'])) {
            // If we have a list of commits, then add them all as builds to be tested:
            $results = [];
            $status  = 'failed';
            foreach ($payload['commits'] as $commit) {
                try {
                    $branch = \str_replace('refs/heads/', '', $payload['ref']);
                    $committer = $commit['author']['email'];
                    $results[$commit['id']] = $this->createBuild(
                        Build::SOURCE_WEBHOOK_PUSH,
                        $project,
                        $commit['id'],
                        $branch,
                        null,
                        $committer,
                        $commit['message']
                    );
                    $status = 'ok';
                } catch (\Throwable $ex) {
                    $results[$commit['id']] = ['status' => 'failed', 'error' => $ex->getMessage()];
                }
            }

            return ['status' => $status, 'commits' => $results];
        }

        return ['status' => 'ignored', 'message' => 'Unusable payload.'];
    }

    /**
     * Handle the payload when Gogs sends a pull request webhook.
     *
     * @throws InvalidArgumentException
     */
    protected function gogsPullRequest(Project $project, array $payload): array
    {
        $pullRequest = $payload['pull_request'];
        $headBranch  = $pullRequest['head_branch'];

        $action          = $payload['action'];
        $activeActions   = ['opened', 'reopened', 'label_updated', 'label_cleared'];
        $inactiveActions = ['closed'];

        $state          = $pullRequest['state'];
        $activeStates   = ['open'];
        $inactiveStates = ['closed'];

        if (!\in_array($action, $activeActions, true) && !\in_array($action, $inactiveActions, true)) {
            return ['status' => 'ignored', 'message' => 'Action ' . $action . ' ignored'];
        }
        if (!\in_array($state, $activeStates, true) && !\in_array($state, $inactiveStates, true)) {
            return ['status' => 'ignored', 'message' => 'State ' . $state . ' ignored'];
        }

        $envs = [];

        // Get environment form labels
        if (\in_array($action, $activeActions, true) && \in_array($state, $activeStates, true)) {
            if (isset($pullRequest['labels']) && \is_array($pullRequest['labels'])) {
                foreach ($pullRequest['labels'] as $label) {
                    if (\strpos($label['name'], 'env:') === 0) {
                        $envs[] = \substr($label['name'], 4);
                    }
                }
            }
        }

        $envsUpdated = [];
        $envObjects  = $project->getEnvironmentsObjects();
        foreach ($envObjects['items'] as $environment) {
            $branches = $environment->getBranches();
            if (\in_array($environment->getName(), $envs, true)) {
                if (!\in_array($headBranch, $branches, true)) {
                    // Add branch to environment
                    $branches[] = $headBranch;
                    $environment->setBranches($branches);
                    $this->environmentStore->save($environment);
                    $envsUpdated[] = $environment->getId();
                }
            } else {
                if (\in_array($headBranch, $branches, true)) {
                    // Remove branch from environment
                    $branches = \array_diff($branches, [$headBranch]);
                    $environment->setBranches($branches);
                    $this->environmentStore->save($environment);
                    $envsUpdated[] = $environment->getId();
                }
            }
        }

        if ('closed' === $state && $pullRequest['merged']) {
            // update base branch environments
            $environmentIds = $project->getEnvironmentsNamesByBranch($pullRequest['base_branch']);
            $envsUpdated    = \array_merge($envsUpdated, $environmentIds);
        }

        $envsUpdated = \array_unique($envsUpdated);
        if (!empty($envsUpdated)) {
            foreach ($envsUpdated as $environmentId) {
                $this->buildService->createBuild(
                    $project,
                    $environmentId,
                    '',
                    $project->getDefaultBranch(),
                    null,
                    null,
                    null,
                    Build::SOURCE_WEBHOOK_PUSH,
                    null,
                    null
                );
            }

            return ['status' => 'ok', 'message' => 'Branch environments updated ' . \implode(', ', $envsUpdated)];
        }

        return ['status' => 'ignored', 'message' => 'Branch environments not changed'];
    }
}
