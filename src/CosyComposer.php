<?php

namespace eiriksm\CosyComposer;

use Composer\Console\Application;
use Composer\Semver\Semver;
use eiriksm\ArrayOutput\ArrayOutput;
use eiriksm\CosyComposer\Exceptions\CanNotUpdateException;
use eiriksm\CosyComposer\Exceptions\ChdirException;
use eiriksm\CosyComposer\Exceptions\ComposerInstallException;
use eiriksm\CosyComposer\Exceptions\GitCloneException;
use eiriksm\CosyComposer\Exceptions\GitPushException;
use eiriksm\CosyComposer\Exceptions\OutsideProcessingHoursException;
use eiriksm\CosyComposer\ListFilterer\DevDepsOnlyFilterer;
use eiriksm\CosyComposer\ListFilterer\IndirectWithDirectFilterer;
use eiriksm\CosyComposer\Providers\PublicGithubWrapper;
use eiriksm\ViolinistMessages\UpdateListItem;
use GuzzleHttp\Psr7\Request;
use Http\Client\HttpClient;
use Violinist\AllowListHandler\AllowListHandler;
use Violinist\ChangelogFetcher\ChangelogRetriever;
use Violinist\ChangelogFetcher\DependencyRepoRetriever;
use Violinist\CommitMessageCreator\Constant\Type;
use Violinist\CommitMessageCreator\Creator;
use Violinist\ComposerLockData\ComposerLockData;
use Violinist\ComposerUpdater\Exception\ComposerUpdateProcessFailedException;
use Violinist\ComposerUpdater\Exception\NotUpdatedException;
use Violinist\ComposerUpdater\Updater;
use Violinist\Config\Config;
use Violinist\GitLogFormat\ChangeLogData;
use eiriksm\ViolinistMessages\ViolinistMessages;
use eiriksm\ViolinistMessages\ViolinistUpdate;
use Github\Client;
use Github\Exception\RuntimeException;
use Github\Exception\ValidationFailedException;
use League\Flysystem\Adapter\Local;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Violinist\ProjectData\ProjectData;
use Violinist\Slug\Slug;
use Wa72\SimpleLogger\ArrayLogger;
use function peterpostmann\uri\parse_uri;

class CosyComposer
{
    const UPDATE_ALL = 'update_all';

    const UPDATE_INDIVIDUAL = 'update_individual';

    private $urlArray;

    /**
     * @var ChangelogRetriever
     */
    private $fetcher;

    /**
     * @var string
     */
    private $commitMessage;

    /**
     * @var bool|string
     */
    private $lockFileContents;

    /**
     * @var ProviderFactory
     */
    protected $providerFactory;

    /**
     * @var \eiriksm\CosyComposer\CommandExecuter
     */
    protected $executer;

    /**
     * @var ComposerFileGetter
     */
    protected $composerGetter;

    /**
     * @var string
     */
    protected $cwd;

    /**
     * @var string
     */
    private $token;

    /**
     * @var Slug
     */
    private $slug;

    /**
     * @var string
     */
    private $userToken;

    /**
     * Github pass.
     *
     * @var string
     */
    private $githubPass;

    /**
     * @var string
     */
    private $forkUser;

    /**
     * @var string
     */
    private $githubUserName;

    /**
     * @var string
     */
    private $githubEmail;

    /**
     * @var ViolinistMessages
     */
    private $messageFactory;

    /**
     * The output we use for updates?
     *
     * @var ArrayOutput
     */
    protected $output;

    /**
     * @var string
     */
    protected $tmpDir;

    /**
     * @var string
     */
    protected $compserJsonDir;

    /**
     * @var string
     */
    private $cacheDir = '/tmp';

    /**
     * @var string
     */
    protected $tmpParent = '/tmp';

    /**
     * @var Application
     */
    private $app;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var null|ProjectData
     */
    protected $project;

    /**
     * @var \Http\Adapter\Guzzle6\Client
     */
    protected $httpClient;

    /**
     * @var string
     */
    protected $tokenUrl;

    /**
     * @var null|object
     */
    private $tempToken = null;

    /**
     * @var bool
     */
    private $isPrivate = false;

    /**
     * @var SecurityCheckerFactory
     */
    private $checkerFactory;

    /**
     * @var ProviderInterface
     */
    private $client;

    /**
     * @var ProviderInterface
     */
    private $privateClient;

    /**
     * @var array
     */
    private $tokens = [];

    /**
     * @param array $tokens
     */
    public function setTokens(array $tokens)
    {
        $this->tokens = $tokens;
    }

    /**
     * @return SecurityCheckerFactory
     */
    public function getCheckerFactory()
    {
        return $this->checkerFactory;
    }

    /**
     * @param string $tokenUrl
     */
    public function setTokenUrl($tokenUrl)
    {
        $this->tokenUrl = $tokenUrl;
    }

    /**
     * @param ProjectData|null $project
     */
    public function setProject($project)
    {
        $this->project = $project;
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        if (!$this->logger) {
            $this->logger = new ArrayLogger();
        }
        return $this->logger;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }


    /**
     * @param string $cacheDir
     */
    public function setCacheDir($cacheDir)
    {
        $this->cacheDir = $cacheDir;
    }

    /**
     * @return string
     */
    public function getCacheDir()
    {
        return $this->cacheDir;
    }

    /**
     * @return HttpClient
     */
    public function getHttpClient()
    {
        if (!$this->httpClient) {
            $this->httpClient = new \Http\Adapter\Guzzle6\Client();
        }
        return $this->httpClient;
    }

    /**
     * @param HttpClient $httpClient
     */
    public function setHttpClient(HttpClient $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    /**
     * @return string
     */
    public function getTmpParent()
    {
        return $this->tmpParent;
    }

    /**
     * @param string $tmpParent
     */
    public function setTmpParent($tmpParent)
    {
        $this->tmpParent = $tmpParent;
    }

    /**
     * @param Application $app
     */
    public function setApp(Application $app)
    {
        $this->app = $app;
    }

    /**
     * @return string
     */
    public function getCwd()
    {
        return $this->cwd;
    }

    /**
     * @return string
     */
    public function getLastStdErr()
    {
        $output = $this->executer->getLastOutput();
        return !empty($output['stderr']) ? $output['stderr'] : '';
    }

    /**
     * @return string
     */
    public function getLastStdOut()
    {
        $output = $this->executer->getLastOutput();
        return !empty($output['stdout']) ? $output['stdout'] : '';
    }

    /**
     * @param \eiriksm\CosyComposer\CommandExecuter $executer
     */
    public function setExecuter($executer)
    {
        $this->executer = $executer;
    }

    /**
     * @param ProviderFactory $providerFactory
     */
    public function setProviderFactory(ProviderFactory $providerFactory)
    {
        $this->providerFactory = $providerFactory;
    }


    /**
     * CosyComposer constructor.
     */
    public function __construct($slug, Application $app, OutputInterface $output, CommandExecuter $executer)
    {
        if ($slug) {
            // @todo: Move to create from URL.
            $this->slug = new Slug();
            $this->slug->setProvider('github.com');
            $this->slug->setSlug($slug);
        }
        $tmpdir = uniqid();
        $this->tmpDir = sprintf('/tmp/%s', $tmpdir);
        $this->messageFactory = new ViolinistMessages();
        $this->app = $app;
        $this->output = $output;
        $this->executer = $executer;
        $this->checkerFactory = new SecurityCheckerFactory();
    }

    public function setUrl($url = null)
    {
        // Make it possible without crashing.
        $slug_url_obj = parse_url($url);
        if (empty($slug_url_obj['port'])) {
            // Set it based on scheme.
            switch ($slug_url_obj['scheme']) {
                case 'http':
                    $slug_url_obj['port'] = 80;
                    break;

                case 'https':
                    $slug_url_obj['port'] = 443;
                    break;
            }
        }
        $this->urlArray = $slug_url_obj;
        $providers = Slug::getSupportedProviders();
        if (!empty($slug_url_obj['host'])) {
            $providers = array_merge($providers, [$slug_url_obj['host']]);
        }
        $this->slug = Slug::createFromUrlAndSupportedProviders($url, $providers);
    }

    public function setGithubAuth($user, $pass)
    {
        $this->userToken = $user;
        $this->forkUser = $user;
        $this->githubPass = $pass;
    }

    public function setUserToken($user_token)
    {
        $this->userToken = $user_token;
    }

    public function setGithubForkAuth($user, $mail)
    {
        $this->githubUserName = $user;
        $this->githubEmail = $mail;
    }

  /**
   * Set a user to fork to.
   *
   * @param string $user
   */
    public function setForkUser($user)
    {
        $this->forkUser = $user;
    }

    protected function handleTimeIntervalSetting($composer_json)
    {
        if (empty($composer_json->extra) ||
            empty($composer_json->extra->violinist)) {
            return;
        }
        // Default timezone is UTC.
        $timezone = new \DateTimeZone('+0000');
        if (!empty($composer_json->extra->violinist->timezone)) {
            try {
                $new_tz = new \DateTimeZone($composer_json->extra->violinist->timezone);
                $timezone = $new_tz;
            } catch (\Exception $e) {
                // Well then the default is used.
            }
        }
        if (!empty($composer_json->extra->violinist->timeframe_disallowed)) {
            // See if it is disallowed then.
            $date = new \DateTime('now', $timezone);
            $hour_parts = explode('-', $composer_json->extra->violinist->timeframe_disallowed);
            if (count($hour_parts) != 2) {
                throw new \Exception('Timeframe disallowed is in the wrong format');
            }
            $low_time_object = new \DateTime($hour_parts[0], $timezone);
            $high_time_object = new \DateTime($hour_parts[1], $timezone);
            if ($date->format('U') > $low_time_object->format('U') && $date->format('U') < $high_time_object->format('U')) {
                throw new OutsideProcessingHoursException('Current hour is inside timeframe disallowed');
            }
        }
    }

    public function handleDrupalContribSa($cdata)
    {
        if (!getenv('DRUPAL_CONTRIB_SA_PATH')) {
            return;
        }
        $symfony_dir = sprintf('%s/.symfony/cache/security-advisories/drupal', getenv('HOME'));
        if (!file_exists($symfony_dir)) {
            $mkdir = $this->execCommand('mkdir -p %s', $symfony_dir);
            if ($mkdir) {
                return;
            }
        }
        $contrib_sa_dir = getenv('DRUPAL_CONTRIB_SA_PATH');
        if (empty($cdata->repositories)) {
            return;
        }
        foreach ($cdata->repositories as $repository) {
            if (empty($repository->url)) {
                continue;
            }
            if ($repository->url === 'https://packages.drupal.org/8') {
                $this->execCommand(sprintf('rsync -aq %s/sa_yaml/8/drupal/* %s/', $contrib_sa_dir, $symfony_dir));
            }
            if ($repository->url === 'https://packages.drupal.org/7') {
                $this->execCommand(sprintf('rsync -aq %s/sa_yaml/7/drupal/* %s/', $contrib_sa_dir, $symfony_dir));
            }
        }
    }

    /**
     * Export things.
     */
    protected function exportEnvVars()
    {
        if (!$this->project) {
            return;
        }
        $env = $this->project->getEnvString();
        if (empty($env)) {
            return;
        }
        // One per line.
        $env_array = preg_split("/\r\n|\n|\r/", $env);
        if (empty($env_array)) {
            return;
        }
        foreach ($env_array as $env_string) {
            if (empty($env_string)) {
                continue;
            }
            $env_parts = explode('=', $env_string, 2);
            if (count($env_parts) != 2) {
                continue;
            }
            // We do not allow to override ENV vars.
            $key = $env_parts[0];
            $existing_env = getenv($key);
            if ($existing_env) {
                $this->getLogger()->log('info', new Message("The ENV variable $key was skipped because it exists and can not be overwritten"));
                continue;
            }
            $value = $env_parts[1];
            $this->getLogger()->log('info', new Message("Exporting ENV variable $key: $value"));
            putenv($env_string);
            $_ENV[$key] = $value;
        }
    }

    /**
     * @throws \eiriksm\CosyComposer\Exceptions\ChdirException
     * @throws \eiriksm\CosyComposer\Exceptions\GitCloneException
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function run()
    {
        // Always start by making sure the .ssh directory exists.
        $directory = sprintf('%s/.ssh', getenv('HOME'));
        if (!file_exists($directory)) {
            if (!@mkdir($directory, 0700) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
            }
        }
        // Export the environment variables if needed.
        $this->exportEnvVars();
        if (!empty($_SERVER['violinist_hostname'])) {
            $this->log(sprintf('Running update check on %s', $_SERVER['violinist_hostname']));
        }
        if (!empty($_SERVER['violinist_revision'])) {
            $this->log(sprintf('Queue starter revision %s', $_SERVER['violinist_revision']));
        }
        if (!empty($_SERVER['queue_runner_revision'])) {
            $this->log(sprintf('Queue runner revision %s', $_SERVER['queue_runner_revision']));
        }
        // Try to get the php version as well.
        $this->execCommand('php --version');
        $this->log($this->getLastStdOut());
        // Try to get the composer version as well.
        $this->execCommand('composer --version');
        $this->log($this->getLastStdOut());
        $this->log(sprintf('Starting update check for %s', $this->slug->getSlug()));
        $user_name = $this->slug->getUserName();
        $user_repo = $this->slug->getUserRepo();
        // First set working dir to /tmp (since we might be in the directory of the
        // last processed item, which may be deleted.
        if (!$this->chdir($this->getTmpParent())) {
            throw new ChdirException('Problem with changing dir to ' . $this->getTmpParent());
        }
        $hostname = $this->slug->getProvider();
        $url = null;
        // Make sure we accept the fingerprint of whatever we are cloning.
        $this->execCommand(sprintf('ssh-keyscan -t rsa,dsa %s >> ~/.ssh/known_hosts', $hostname));
        if (!empty($_SERVER['private_key'])) {
            $this->log('Checking for existing private key');
            $filename = "$directory/id_rsa";
            if (!file_exists($filename)) {
                $this->log('Installing private key');
                file_put_contents($filename, $_SERVER['private_key']);
                $this->execCommand(sprintf('chmod 600 %s', $filename), false);
            }
        }
        switch ($hostname) {
            case 'github.com':
                $url = sprintf('https://%s:%s@github.com/%s', $this->userToken, $this->githubPass, $this->slug->getSlug());
                break;

            case 'gitlab.com':
                $url = sprintf('https://oauth2:%s@gitlab.com/%s', $this->userToken, $this->slug->getSlug());
                break;

            case 'bitbucket.org':
                $url = sprintf('https://x-token-auth:%s@bitbucket.org/%s.git', $this->userToken, $this->slug->getSlug());
                break;

            default:
                $url = sprintf('%s://oauth2:%s@%s:%d/%s', $this->urlArray['scheme'], $this->userToken, $hostname, $this->urlArray['port'], $this->slug->getSlug());
                break;
        }
        $this->log('Cloning repository');
        $clone_result = $this->execCommand('git clone --depth=1 ' . $url . ' ' . $this->tmpDir, false, 120);
        if ($clone_result) {
            // We had a problem.
            $this->log($this->getLastStdOut(), Message::COMMAND);
            $this->log($this->getLastStdErr(), Message::COMMAND);
            throw new GitCloneException('Problem with the execCommand git clone. Exit code was ' . $clone_result);
        }
        $this->log('Repository cloned');
        $composer_json_dir = $this->tmpDir;
        if ($this->project && $this->project->getComposerJsonDir()) {
            $composer_json_dir = sprintf('%s/%s', $this->tmpDir, $this->project->getComposerJsonDir());
        }
        $this->compserJsonDir = $composer_json_dir;
        if (!$this->chdir($this->compserJsonDir)) {
            throw new ChdirException('Problem with changing dir to the clone dir.');
        }
        $local_adapter = new Local($this->compserJsonDir);
        if (!empty($_ENV['config_branch'])) {
            $config_branch = $_ENV['config_branch'];
            $this->log('Changing to config branch: ' . $config_branch);
            $tmpdir = sprintf('/tmp/%s', uniqid('', true));
            $clone_result = $this->execCommand('git clone --depth=1 ' . $url . ' ' . $tmpdir . ' -b ' . $config_branch, false, 120);
            if (!$clone_result) {
                $local_adapter = new Local($tmpdir);
            }
        }
        $this->composerGetter = new ComposerFileGetter($local_adapter);
        if (!$this->composerGetter->hasComposerFile()) {
            throw new \InvalidArgumentException('No composer.json file found.');
        }
        $composer_json_data = $this->composerGetter->getComposerJsonData();
        if (false == $composer_json_data) {
            throw new \InvalidArgumentException('Invalid composer.json file');
        }
        $config = Config::createFromComposerData($composer_json_data);
        $this->client = $this->getClient($this->slug);
        $this->privateClient = $this->getClient($this->slug);
        $this->privateClient->authenticate($this->userToken, null);
        try {
            $this->isPrivate = $this->privateClient->repoIsPrivate($this->slug);
            // Get the default branch of the repo.
            $default_branch = $this->privateClient->getDefaultBranch($this->slug);
        } catch (\Throwable $e) {
            // Could be a personal access token.
            if (!method_exists($this->privateClient, 'authenticatePersonalAccessToken')) {
                throw $e;
            }
            try {
                $this->privateClient->authenticatePersonalAccessToken($this->userToken, null);
                $this->isPrivate = $this->privateClient->repoIsPrivate($this->slug);
                // Get the default branch of the repo.
                $default_branch = $this->privateClient->getDefaultBranch($this->slug);
            } catch (\Throwable $other_exception) {
                // Throw the first exception, probably.
                throw $e;
            }
        }
        // We also allow the project to override this for violinist.
        if ($config->getDefaultBranch()) {
            // @todo: Would be better to make sure this can actually be set, based on the branches available. Either
            // way, if a person configures this wrong, several parts will fail spectacularly anyway.
            $default_branch = $config->getDefaultBranch();
        }
        // Now make sure we are actually on that branch.
        if ($this->execCommand('git remote set-branches origin "*"')) {
            throw new \Exception('There was an error trying to configure default branch');
        }
        if ($this->execCommand('git fetch origin ' . $default_branch)) {
            throw new \Exception('There was an error trying to fetch default branch');
        }
        if ($this->execCommand('git checkout ' . $default_branch)) {
            throw new \Exception('There was an error trying to switch to default branch');
        }
        // Re-read the composer.json file, since it can be different on the default branch,
        $composer_json_data = $this->composerGetter->getComposerJsonData();
        $this->runAuthExport($hostname);
        $this->handleDrupalContribSa($composer_json_data);
        $config = Config::createFromComposerData($composer_json_data);
        $this->handleTimeIntervalSetting($composer_json_data);
        $lock_file = $this->compserJsonDir . '/composer.lock';
        $initial_composer_lock_data = false;
        $security_alerts = [];
        if (@file_exists($lock_file)) {
            // We might want to know whats in here.
            $initial_composer_lock_data = json_decode(file_get_contents($lock_file));
        }
        $this->lockFileContents = $initial_composer_lock_data;
        if ($config->shouldAlwaysUpdateAll() && !$initial_composer_lock_data) {
            $this->log('Update all enabled, but no lock file present. This is not supported');
            $this->cleanUp();
            return;
        }
        $app = $this->app;
        $d = $app->getDefinition();
        $opts = $d->getOptions();
        $opts['no-ansi'] = new InputOption('no-ansi', null, 4, true, 'Disable ANSI output');
        $d->setOptions($opts);
        $app->setDefinition($d);
        $app->setAutoExit(false);
        $this->doComposerInstall($config);
        // Now read the lockfile.
        $composer_lock_after_installing = json_decode(@file_get_contents($this->compserJsonDir . '/composer.lock'));
        // And do a quick security check in there as well.
        try {
            $this->log('Checking for security issues in project.');
            $checker = $this->checkerFactory->getChecker();
            $result = $checker->checkDirectory($this->compserJsonDir);
            // Make sure this is an array now.
            if (!$result) {
                $result = [];
            }
            $this->log('Found ' . count($result) . ' security advisories for packages installed', 'message', [
                'result' => $result,
            ]);
            foreach ($result as $name => $value) {
                $this->log("Security update available for $name");
            }
            if (count($result)) {
                $security_alerts = $result;
            }
        } catch (\Exception $e) {
            $this->log('Caught exception while looking for security updates:');
            $this->log($e->getMessage());
        }
        // We also want to consult the Drupal security advisories, since the FriendsOfPHP/security-advisories
        // repo is a manual job merging and maintaining. On top of that, it requires the built container to be
        // up to date. So here could be several hours of delay on critical stuff.
        $this->attachDrupalAdvisories($security_alerts);
        $array_input_array = [
            'outdated',
            '-d' => $this->getCwd(),
            '--minor-only' => true,
            '--format' => 'json',
            '--no-interaction' => true,
            '--direct' => false,
        ];
        if ($config->shouldCheckDirectOnly()) {
            $array_input_array['--direct'] = true;
        }
        // If we should always update all, then of course we should not only check direct dependencies outdated.
        // Regardless of the option above actually.
        if ($config->shouldAlwaysUpdateAll()) {
            $array_input_array['--direct'] = false;
        }
        // If we should allow indirect packages to updated via running composer update my/direct, then we need to
        // uncover which indirect are actually out of date. Meaning direct is required to be false.
        if ($config->shouldUpdateIndirectWithDirect()) {
            $array_input_array['--direct'] = false;
        }
        $i = new ArrayInput($array_input_array);
        $app->run($i, $this->output);
        $raw_data = $this->output->fetch();
        $data = null;
        foreach ($raw_data as $delta => $item) {
            if (empty($item) || empty($item[0])) {
                continue;
            }
            if (!is_array($item)) {
                // Can't be it.
                continue;
            }
            foreach ($item as $value) {
                if (!$json_update = @json_decode($value)) {
                    // Not interesting.
                    continue;
                }
                if (!isset($json_update->installed)) {
                    throw new \Exception(
                        'JSON output from composer was not looking as expected after checking updates'
                    );
                }
                $data = $json_update->installed;
                break;
            }
        }
        if (!is_array($data)) {
            $this->log('Update data was in wrong format or missing. This is an error in violinist and should be reported');
            $this->log(print_r($raw_data, true), Message::COMMAND, [
              'data' => $raw_data,
              'data_guessed' => $data,
            ]);
            $this->cleanUp();
            return;
        }
        // Only update the ones in the allow list, if indicated.
        $handler = AllowListHandler::createFromConfig($config);
        $handler->setLogger($this->getLogger());
        $data = $handler->applyToItems($data);
        // Remove non-security packages, if indicated.
        if ($config->shouldOnlyUpdateSecurityUpdates()) {
            $this->log('Project indicated that it should only receive security updates. Removing non-security related updates from queue');
            foreach ($data as $delta => $item) {
                try {
                    $package_name_in_composer_json = self::getComposerJsonName($composer_json_data, $item->name, $this->compserJsonDir);
                    if (isset($security_alerts[$package_name_in_composer_json])) {
                        continue;
                    }
                } catch (\Exception $e) {
                    // Totally fine. Let's check if it's there just by the name we have right here.
                    if (isset($security_alerts[$item->name])) {
                        continue;
                    }
                }
                unset($data[$delta]);
                $this->log(sprintf('Skipping update of %s because it is not indicated as a security update', $item->name));
            }
        }
        // Remove block listed packages.
        $block_list = $config->getBlockList();
        if (!is_array($block_list)) {
                $this->log('The format for the package block list was not correct. Expected an array, got ' . gettype($composer_json_data->extra->violinist->blacklist), Message::VIOLINIST_ERROR);
        } else {
            foreach ($data as $delta => $item) {
                if (in_array($item->name, $block_list)) {
                    $this->log(sprintf('Skipping update of %s because it is on the block list', $item->name), Message::BLACKLISTED, [
                        'package' => $item->name,
                    ]);
                    unset($data[$delta]);
                    continue;
                }
                // Also try to match on wildcards.
                foreach ($block_list as $block_list_item) {
                    if (fnmatch($block_list_item, $item->name)) {
                        $this->log(sprintf('Skipping update of %s because it is on the block list by pattern %s', $item->name, $block_list_item), Message::BLACKLISTED, [
                            'package' => $item->name,
                        ]);
                        unset($data[$delta]);
                        continue 2;
                    }
                }
            }
        }
        // Remove dev dependencies, if indicated.
        if (!$config->shouldUpdateDevDependencies()) {
            $filterer = DevDepsOnlyFilterer::create($composer_lock_after_installing, $composer_json_data);
            $data = $filterer->filter($data);
        }
        foreach ($data as $delta => $item) {
            // Also unset those that are in an unexpected format. A new thing seen in the wild has been this:
            // {
            //    "name": "symfony/css-selector",
            //    "version": "v2.8.49",
            //    "description": "Symfony CssSelector Component"
            // }
            // They should ideally include a latest version and latest status.
            if (!isset($item->latest) || !isset($item->{'latest-status'})) {
                unset($data[$delta]);
            } else {
                // If a package is abandoned, we do not really want to know. Since we can't update it anyway.
                if (isset($item->version) && ($item->latest === $item->version || $item->{'latest-status'} === 'up-to-date')) {
                    unset($data[$delta]);
                }
            }
        }
        if (empty($data)) {
            $this->log('No updates found');
            $this->cleanUp();
            return;
        }
        // Try to log what updates are found.
        $this->log('The following updates were found:');
        $updates_string = '';
        foreach ($data as $delta => $item) {
            $updates_string .= sprintf(
                "%s: %s installed, %s available (type %s)\n",
                $item->name,
                $item->version,
                $item->latest,
                $item->{'latest-status'}
            );
        }
        $this->log($updates_string, Message::UPDATE, [
            'packages' => $data,
        ]);
        // Try to see if we have already dealt with this (i.e already have a branch for all the updates.
        $branch_user = $this->forkUser;
        if ($this->isPrivate) {
            $branch_user = $user_name;
        }
        $branch_slug = new Slug();
        $branch_slug->setProvider('github.com');
        $branch_slug->setUserName($branch_user);
        $branch_slug->setUserRepo($user_repo);
        $branches_flattened = [];
        $prs_named = [];
        $default_base = null;
        $total_prs = 0;
        try {
            if ($default_base_upstream = $this->privateClient->getDefaultBase($this->slug, $default_branch)) {
                $default_base = $default_base_upstream;
            }
            $prs_named = $this->privateClient->getPrsNamed($this->slug);
            // These can fail if we have not yet created a fork, and the repo is public. That is why we have them at the
            // end of this try/catch, so we can still know the default base for the original repo, and its pull
            // requests.
            if (!$default_base) {
                $default_base = $this->getPrClient()->getDefaultBase($branch_slug, $default_branch);
            }
            $branches_flattened = $this->getPrClient()->getBranchesFlattened($branch_slug);
        } catch (RuntimeException $e) {
            // Safe to ignore.
            $this->log('Had a runtime exception with the fetching of branches and Prs: ' . $e->getMessage());
        }
        $violinist_config = (object) [];
        if (!empty($composer_json_data->extra) && !empty($composer_json_data->extra->violinist)) {
            $violinist_config = $composer_json_data->extra->violinist;
        }
        $one_pr_per_dependency = false;
        if (!empty($violinist_config->one_pull_request_per_package)) {
            $one_pr_per_dependency = (bool) $violinist_config->one_pull_request_per_package;
        }
        foreach ($data as $delta => $item) {
            $branch_name = $this->createBranchName($item, $one_pr_per_dependency, $config);
            if (in_array($branch_name, $branches_flattened)) {
                // Is there a PR for this?
                if (array_key_exists($branch_name, $prs_named)) {
                    if (!$default_base && !$one_pr_per_dependency) {
                        $this->log(sprintf('Skipping %s because a pull request already exists', $item->name), Message::PR_EXISTS, [
                            'package' => $item->name,
                        ]);
                        unset($data[$delta]);
                        $total_prs++;
                    }
                    // Is the pr up to date?
                    if ($prs_named[$branch_name]['base']['sha'] == $default_base) {
                        // Create a fake "post-update-data" object.
                        $fake_post_update = (object) [
                            'version' => $item->latest,
                        ];
                        $security_update = false;
                        $package_name_in_composer_json = self::getComposerJsonName($composer_json_data, $item->name, $this->compserJsonDir);
                        if (isset($security_alerts[$package_name_in_composer_json])) {
                            $security_update = true;
                        }
                        // If the title does not match, it means either has there arrived a security issue for the
                        // update (new title), or we are doing "one-per-dependency", and the title should be something
                        // else with this new update. Either way, we want to continue this. Continue in this context
                        // would mean, we want to keep this for update checking still, and not unset it from the update
                        // array. This will mean it will probably get an updated title later.
                        if ($prs_named[$branch_name]['title'] != $this->createTitle($item, $fake_post_update, $security_update)) {
                            $this->log(sprintf('Updating the PR of %s since the computed title does not match the title.', $item->name), Message::MESSAGE);
                            continue;
                        }
                        $context = [
                            'package' => $item->name,
                        ];
                        if (!empty($prs_named[$branch_name]['html_url'])) {
                            $context['url'] = $prs_named[$branch_name]['html_url'];
                        }
                        $this->log(sprintf('Skipping %s because a pull request already exists', $item->name), Message::PR_EXISTS, $context);
                        unset($data[$delta]);
                        $total_prs++;
                    }
                }
            }
        }
        if ($config->shouldUpdateIndirectWithDirect()) {
            $filterer = IndirectWithDirectFilterer::create($composer_lock_after_installing, $composer_json_data);
            $data = $filterer->filter($data);
        }
        if (!count($data)) {
            $this->log('No updates that have not already been pushed.');
            $this->cleanUp();
            return;
        }

        // Unshallow the repo, for syncing it.
        $this->execCommand('git pull --unshallow', false, 300);
        // If the repo is private, we need to push directly to the repo.
        if (!$this->isPrivate) {
            $this->preparePrClient();
            $this->log('Creating fork to ' . $this->forkUser);
            $this->client->createFork($user_name, $user_repo, $this->forkUser);
        }
        $update_type = self::UPDATE_INDIVIDUAL;
        if ($config->shouldAlwaysUpdateAll()) {
            $update_type = self::UPDATE_ALL;
        }
        $this->log('Config suggested update type ' . $update_type);
        if ($this->project && $this->project->shouldUpdateAll()) {
            // Only log this if this might end up being surprising. I mean override all with all. So what?
            if ($update_type === self::UPDATE_INDIVIDUAL) {
                $this->log('Override of update type from project data. Probably meaning first run, allowed update all');
            }
            $update_type = self::UPDATE_ALL;
        }
        switch ($update_type) {
            case self::UPDATE_INDIVIDUAL:
                $this->handleIndividualUpdates($data, $composer_lock_after_installing, $composer_json_data, $one_pr_per_dependency, $initial_composer_lock_data, $prs_named, $default_base, $hostname, $default_branch, $security_alerts, $total_prs);
                break;

            case self::UPDATE_ALL:
                $this->handleUpdateAll($initial_composer_lock_data, $composer_lock_after_installing, $security_alerts, $config, $default_base, $default_branch, $prs_named);
                break;
        }
        // Clean up.
        $this->cleanUp();
    }

    protected function handleUpdateAll($initial_composer_lock_data, $composer_lock_after_installing, $alerts, Config $config, $default_base, $default_branch, $prs_named)
    {
        // We are going to hack an item here. We want the package to be "all" and the versions to be blank.
        $item = (object) [
            'name' => 'violinist-all',
            'version' => '',
            'latest' => '',
        ];
        $branch_name = $this->createBranchName($item, false, $config);
        $pr_params = [];
        $security_update = false;
        try {
            $this->switchBranch($branch_name);
            $status = $this->execCommand('composer update');
            if ($status) {
                throw new \Exception('Composer update command existed with status code ' . $status);
            }
            // Now let's find out what has actually been updated.
            $new_lock_contents = json_decode(file_get_contents($this->compserJsonDir . '/composer.lock'));
            $comparer = new LockDataComparer($composer_lock_after_installing, $new_lock_contents);
            $list = $comparer->getUpdateList();
            if (empty($list)) {
                // That's too bad. Let's throw an exception for this.
                throw new \UnexpectedValueException('No updates detected after running composer update');
            }
            // Now see if any of the packages updated was in the alerts.
            foreach ($list as $value) {
                if (empty($alerts[$value->getPackageName()])) {
                    continue;
                }
                $security_update = true;
            }
            $this->log('Successfully ran command composer update for all packages');
            $title = 'Update all composer dependencies';
            if ($security_update) {
                // @todo: Use message factory and package.
                $title = sprintf('[SECURITY] %s', $title);
            }
            // We can do this, since the body creates a title, which it does not use. This is only used for the title.
            // Which, again, we do not use.
            $fake_item = $fake_post = (object) [
                'name' => 'all',
                'version' => '0.0.0',
            ];
            $body = $this->createBody($fake_item, $fake_post, null, $security_update, $list);
            $pr_params = $this->getPrParams($branch_name, $body, $title, $default_branch, $config);
            // OK, so... If we already have a branch named the name we are about to use. Is that one a branch
            // containing all the updates we now got? And is it actually up to date with the target branch? Of course,
            // if there is no such branch, then we will happily push it.
            if (!empty($prs_named[$branch_name])) {
                $up_to_date = false;
                if (!empty($prs_named[$branch_name]['base']['sha']) && $prs_named[$branch_name]['base']['sha'] == $default_base) {
                    $up_to_date = true;
                }
                $should_update = $this->shouldUpdatePr($branch_name, $pr_params, $prs_named);
                if (!$should_update && $up_to_date) {
                    // Well well well. Let's not push this branch over and over, shall we?
                    $this->log(sprintf('The branch %s with all updates is already up to date. Aborting the PR update', $branch_name));
                    return;
                }
            }
            $this->commitFilesForAll($config);
            $this->pushCode($branch_name, $default_base, $initial_composer_lock_data);
            $pullRequest = $this->createPullrequest($pr_params);
            if (!empty($pullRequest['html_url'])) {
                $this->log($pullRequest['html_url'], Message::PR_URL, [
                    'package' => 'all',
                ]);
            }
        } catch (ValidationFailedException $e) {
            // @todo: Do some better checking. Could be several things, this.
            $this->handlePossibleUpdatePrScenario($e, $branch_name, $pr_params, $prs_named);
        } catch (\Gitlab\Exception\RuntimeException $e) {
            $this->handlePossibleUpdatePrScenario($e, $branch_name, $pr_params, $prs_named);
        } catch (\Throwable $e) {
            $this->log('Caught exception while running update all: ' . $e->getMessage());
        }
    }

    protected function commitFilesForAll(Config $config)
    {
        $this->cleanRepoForCommit();
        $creator = $this->getCommitCreator($config);
        $msg = $creator->generateMessageFromString('Update all dependencies');
        $this->commitFiles($msg);
    }

    protected function switchBranch($branch_name)
    {
        $this->log('Checking out new branch: ' . $branch_name);
        $result = $this->execCommand('git checkout -b ' . $branch_name, false);
        if ($result) {
            $this->log($this->getLastStdErr());
            throw new \Exception(sprintf('There was an error checking out branch %s. Exit code was %d', $branch_name, $result));
        }
        // Make sure we do not have any uncommitted changes.
        $this->execCommand('git checkout .', false);
    }

    protected function cleanRepoForCommit()
    {
        // Clean up the composer.lock file if it was not part of the repo.
        $this->execCommand('git clean -f composer.*');
    }

    protected function getCommitCreator(Config $config) : Creator
    {
        $creator = new Creator();
        $type = Type::NONE;
        $creator->setType($type);
        try {
            if ($config) {
                $creator->setType($config->getCommitMessageConvention());
            }
        } catch (\InvalidArgumentException $e) {
            // Fall back to using none.
        }
        return $creator;
    }

    protected function commitFilesForPackage(UpdateListItem $item, Config $config, $is_dev = false)
    {
        $this->cleanRepoForCommit();
        $creator = $this->getCommitCreator($config);
        $msg = $creator->generateMessage($item, $is_dev);
        $this->commitFiles($msg);
    }

    protected function commitFiles($msg)
    {

        $command = sprintf(
            'GIT_AUTHOR_NAME="%s" GIT_AUTHOR_EMAIL="%s" GIT_COMMITTER_NAME="%s" GIT_COMMITTER_EMAIL="%s" git commit %s -m "%s"',
            $this->githubUserName,
            $this->githubEmail,
            $this->githubUserName,
            $this->githubEmail,
            $this->lockFileContents ? 'composer.json composer.lock' : 'composer.json',
            $msg
        );
        if ($this->execCommand($command, false)) {
            $this->log($this->getLastStdOut(), Message::COMMAND);
            $this->log($this->getLastStdErr(), Message::COMMAND);
            throw new \Exception('Error committing the composer files. They are probably not changed.');
        }
        $this->commitMessage = $msg;
    }

    protected function runAuthExportToken($hostname, $token)
    {
        if (empty($token)) {
            return;
        }
        switch ($hostname) {
            case 'github.com':
                $this->execCommand(
                    sprintf('composer config --auth github-oauth.github.com %s', $token),
                    false
                );
                break;

            case 'gitlab.com':
                $this->execCommand(
                    sprintf('composer config --auth gitlab-oauth.gitlab.com %s', $token),
                    false
                );
                break;

            case 'bitbucket.org':
                $this->execCommand(
                    sprintf('composer config --auth http-basic.bitbucket.org x-token-auth %s', $token),
                    false
                );
                break;

            default:
                $this->execCommand(
                    sprintf('composer config --auth gitlab-oauth.%s %s', $token, $hostname),
                    false
                );
                break;
        }
    }

    protected function getPrParams($branch_name, $body, $title, $default_branch, Config $config)
    {
        $head = $this->forkUser . ':' . $branch_name;
        if ($this->isPrivate) {
            $head = $branch_name;
        }
        if ($this->slug->getProvider() === 'bitbucket.org') {
            // Currently does not support having the collapsible section thing.
            // @todo: Revisit from time to time?
            // @todo: Make sure we replace the correct one. What if the changelog has this in it?
            $body = str_replace([
                '<details>',
                '<summary>',
                '</summary>',
                '</details>',
            ], '', $body);
        }
        $assignees = $config->getAssignees();
        $assignees_allowed_roles = [
            'agency',
            'enterprise',
        ];
        $assignees_allowed = false;
        if ($this->project && $this->project->getRoles()) {
            foreach ($this->project->getRoles() as $role) {
                if (in_array($role, $assignees_allowed_roles)) {
                    $assignees_allowed = true;
                }
            }
        }
        if (!$assignees_allowed) {
            $assignees = [];
        }
        return [
            'base'  => $default_branch,
            'head'  => $head,
            'title' => $title,
            'body'  => $body,
            'assignees' => $assignees,
        ];
    }

    protected function createPullrequest($pr_params)
    {
        $this->log('Creating pull request from ' . $pr_params['head']);
        return $this->getPrClient()->createPullRequest($this->slug, $pr_params);
    }

    protected function pushCode($branch_name, $default_base, $lock_file_contents)
    {
        if ($this->isPrivate) {
            $origin = 'origin';
            if ($this->execCommand("git push $origin $branch_name --force")) {
                throw new GitPushException('Could not push to ' . $branch_name);
            }
        } else {
            $this->preparePrClient();
            /** @var PublicGithubWrapper $this_client */
            $this_client = $this->client;
            $this_client->forceUpdateBranch($branch_name, $default_base);
            $msg = $this->commitMessage;
            $this_client->commitNewFiles($this->tmpDir, $default_base, $branch_name, $msg, $lock_file_contents);
        }
    }

    protected function runAuthExport($hostname)
    {
        // If we have multiple auth tokens, export them all.
        if (!empty($this->tokens)) {
            foreach ($this->tokens as $token_hostname => $token) {
                $this->runAuthExportToken($token_hostname, $token);
            }
        }
        $this->runAuthExportToken($hostname, $this->userToken);
    }

    protected function handleIndividualUpdates($data, $lockdata, $cdata, $one_pr_per_dependency, $lock_file_contents, $prs_named, $default_base, $hostname, $default_branch, $alerts, $total_prs)
    {
        $config = Config::createFromComposerData($cdata);
        $max_number_of_prs = $config->getNumberOfAllowedPrs();
        foreach ($data as $item) {
            if ($max_number_of_prs && $total_prs >= $max_number_of_prs) {
                $this->log(sprintf('Skipping %s because the number of max concurrent PRs (%d) seems to have been reached', $item->name, $max_number_of_prs), Message::PR_EXISTS, [
                    'package' => $item->name,
                ]);
                continue;
            }
            $security_update = false;
            $package_name = $item->name;
            $branch_name = '';
            $pr_params = [];
            try {
                $pre_update_data = $this->getPackageData($package_name, $lockdata);
                $version_from = $item->version;
                $version_to = $item->latest;
                // See where this package is.
                try {
                    $package_name_in_composer_json = self::getComposerJsonName($cdata, $package_name, $this->compserJsonDir);
                } catch (\Exception $e) {
                    // If this was a package that we somehow got because we have allowed to update other than direct
                    // dependencies we can avoid re-throwing this.
                    if ($config->shouldCheckDirectOnly()) {
                        throw $e;
                    }
                    // Taking a risk :o.
                    $package_name_in_composer_json = $package_name;
                }
                if (isset($alerts[$package_name_in_composer_json])) {
                    $security_update = true;
                }
                $req_item = '';
                $is_require_dev = false;
                if (!empty($cdata->{'require-dev'}->{$package_name_in_composer_json})) {
                    $req_item = $cdata->{'require-dev'}->{$package_name_in_composer_json};
                    $is_require_dev = true;
                } else {
                    // @todo: Support getting req item from a merge plugin as well.
                    if (isset($cdata->{'require'}->{$package_name_in_composer_json})) {
                        $req_item = $cdata->{'require'}->{$package_name_in_composer_json};
                    }
                }
                $can_update_beyond = true;
                $should_update_beyond = false;
                // See if the new version seems to satisfy the constraint. Unless the constraint is dev related somehow.
                try {
                    if (strpos((string) $req_item, 'dev') === false && !Semver::satisfies($version_to, (string)$req_item)) {
                        // Well, unless we have actually disallowed this through config.
                        // @todo: Move to somewhere more central (and certainly outside a loop), and probably together
                        // with other config.
                        $should_update_beyond = true;
                        if (!empty($cdata->extra) && !empty($cdata->extra->violinist) && isset($cdata->extra->violinist->allow_updates_beyond_constraint)) {
                            $can_update_beyond = (bool) $cdata->extra->violinist->allow_updates_beyond_constraint;
                        }
                        if (!$can_update_beyond) {
                            throw new CanNotUpdateException(sprintf('Package %s with the constraint %s can not be updated to %s.', $package_name, $req_item, $version_to));
                        }
                    }
                } catch (CanNotUpdateException $e) {
                    // Re-throw.
                    throw $e;
                } catch (\Exception $e) {
                    // Could be, some times, that we try to check a constraint that semver does not recognize. That is
                    // totally fine.
                }

                // Create a new branch.
                $branch_name = $this->createBranchName($item, $one_pr_per_dependency, $config);
                $this->switchBranch($branch_name);
                // Try to use the same version constraint.
                $version = (string) $req_item;
                // @todo: This is not nearly something that covers the world of constraints. Probably possible to use
                // something from composer itself here.
                $constraint = '';
                if (!empty($version[0])) {
                    switch ($version[0]) {
                        case '^':
                            $constraint = '^';
                            break;

                        case '~':
                            $constraint = '~';
                            break;

                        default:
                            $constraint = '';
                            break;
                    }
                }
                $update_with_deps = true;
                if (!empty($cdata->extra) && !empty($cdata->extra->violinist) && isset($cdata->extra->violinist->update_with_dependencies)) {
                    if (!(bool) $cdata->extra->violinist->update_with_dependencies) {
                        $update_with_deps = false;
                    }
                }
                $updater = new Updater($this->getCwd(), $package_name);
                $cosy_logger = new CosyLogger();
                $cosy_factory_wrapper = new ProcessFactoryWrapper();
                $cosy_factory_wrapper->setExecutor($this->executer);
                $cosy_logger->setLogger($this->getLogger());
                // See if this package has any bundled updates.
                $bundled_packages = $config->getBundledPackagesForPackage($package_name);
                if (!empty($bundled_packages)) {
                    $updater->setBundledPackages($bundled_packages);
                }
                $updater->setLogger($cosy_logger);
                $updater->setProcessFactory($cosy_factory_wrapper);
                $updater->setWithUpdate($update_with_deps);
                $updater->setConstraint($constraint);
                $updater->setDevPackage($is_require_dev);
                $updater->setRunScripts($config->shouldRunScripts());
                if ($config->shouldUpdateIndirectWithDirect()) {
                    $updater->setShouldThrowOnUnupdated(false);
                }
                if (!$lock_file_contents || ($should_update_beyond && $can_update_beyond)) {
                    $updater->executeRequire($version_to);
                } else {
                    $this->log('Running composer update for package ' . $package_name);
                    $updater->executeUpdate();
                }
                $post_update_data = $updater->getPostUpdateData();
                if (isset($post_update_data->source) && $post_update_data->source->type == 'git' && isset($pre_update_data->source)) {
                    $version_from = $pre_update_data->source->reference;
                    $version_to = $post_update_data->source->reference;
                }
                // Now, see if the update was actually to the version we are expecting.
                // If we are updating to another dev version, composer show will tell us something like:
                // dev-master 15eb463
                // while the post update data version will still say:
                // dev-master.
                // So to compare these, we compare the hashes, if the version latest we are updating to
                // matches the dev regex.
                if (preg_match('/dev-\S* /', $item->latest)) {
                    $sha = preg_replace('/dev-\S* /', '', $item->latest);
                    // Now if the version_to matches this, we have updated to the expected version.
                    if (strpos($version_to, $sha) === 0) {
                        $post_update_data->version = $item->latest;
                    }
                }
                // If the item->latest key is set to dependencies, we actually want to allow the branch to change, since
                // the version of the package will of course be an actual version instead of the version called
                // "latest".
                if ('dependencies' !== $item->latest && $post_update_data->version != $item->latest) {
                    $new_branch_name = $this->createBranchNameFromVersions(
                        $item->name,
                        $item->version,
                        $post_update_data->version
                    );
                    $this->log('Changing branch because of an unexpected update result: ' . $branch_name);
                    $this->execCommand('git checkout -b ' . $new_branch_name, false);
                    $branch_name = $new_branch_name;
                    // Check if this new branch name has a pr up-to-date.
                    if (array_key_exists($branch_name, $prs_named)) {
                        if (!$default_base) {
                            $this->log(sprintf('Skipping %s because a pull request already exists', $item->name), Message::PR_EXISTS, [
                                'package' => $item->name,
                            ]);
                            $total_prs++;
                            continue;
                        }
                        // Is the pr up to date?
                        if ($prs_named[$branch_name]['base']['sha'] == $default_base) {
                            $this->log(sprintf('Skipping %s because a pull request already exists', $item->name), Message::PR_EXISTS, [
                                'package' => $item->name,
                            ]);
                            $total_prs++;
                            continue;
                        }
                    }
                }
                $this->log('Successfully ran command composer update for package ' . $package_name);
                $new_lock_data = json_decode(file_get_contents($this->compserJsonDir . '/composer.lock'));
                $list_item = new UpdateListItem($package_name, $post_update_data->version, $item->version);
                $this->commitFilesForPackage($list_item, $config, $is_require_dev);
                $this->runAuthExport($hostname);
                $this->pushCode($branch_name, $default_base, $lock_file_contents);
                $this->log('Trying to retrieve changelog for ' . $package_name);
                $changelog = null;
                $changed_files = [];
                try {
                    $changelog = $this->retrieveChangeLog($package_name, $lockdata, $version_from, $version_to);
                    $this->log('Changelog retrieved');
                } catch (\Throwable $e) {
                    // If the changelog can not be retrieved, we can live with that.
                    $this->log('Exception for changelog: ' . $e->getMessage());
                }
                try {
                    $changed_files = $this->retrieveChangedFiles($package_name, $lockdata, $version_from, $version_to);
                    $this->log('Changed files retrieved');
                } catch (\Throwable $e) {
                    // If the changed files can not be retrieved, we can live with that.
                    $this->log('Exception for retrieving changed files: ' . $e->getMessage());
                }
                $comparer = new LockDataComparer($lockdata, $new_lock_data);
                $update_list = $comparer->getUpdateList();
                $body = $this->createBody($item, $post_update_data, $changelog, $security_update, $update_list, $changed_files);
                $title = $this->createTitle($item, $post_update_data, $security_update);
                $pr_params = $this->getPrParams($branch_name, $body, $title, $default_branch, $config);
                $pullRequest = $this->createPullrequest($pr_params);
                if (!empty($pullRequest['html_url'])) {
                    $this->log($pullRequest['html_url'], Message::PR_URL, [
                        'package' => $package_name,
                    ]);
                }
                $total_prs++;
            } catch (CanNotUpdateException $e) {
                $this->log($e->getMessage(), Message::UNUPDATEABLE, [
                    'package' => $package_name,
                ]);
            } catch (NotUpdatedException $e) {
                // Not updated because of the composer command, not the
                // restriction itself.
                $command = sprintf('composer why-not %s "%s"', $item->name, $item->latest);
                $this->execCommand(sprintf('%s', $command), false);
                $this->log($this->getLastStdErr(), Message::COMMAND, [
                    'command' => $command,
                    'package' => $item->name,
                    'type' => 'stderr',
                ]);
                $this->log($this->getLastStdOut(), Message::COMMAND, [
                    'command' => $command,
                    'package' => $item->name,
                    'type' => 'stdout',
                ]);
                $this->log("$package_name was not updated running composer update", Message::NOT_UPDATED, [
                    'package' => $package_name,
                ]);
            } catch (ValidationFailedException $e) {
                // @todo: Do some better checking. Could be several things, this.
                $this->handlePossibleUpdatePrScenario($e, $branch_name, $pr_params, $prs_named);
            } catch (\Gitlab\Exception\RuntimeException $e) {
                $this->handlePossibleUpdatePrScenario($e, $branch_name, $pr_params, $prs_named);
            } catch (ComposerUpdateProcessFailedException $e) {
                $this->log('Caught an exception: ' . $e->getMessage(), 'error');
                $this->log($e->getErrorOutput(), Message::COMMAND, [
                    'type' => 'exit_code_output',
                    'package' => $package_name,
                ]);
            } catch (\Throwable $e) {
                // @todo: Should probably handle this in some way.
                $this->log('Caught an exception: ' . $e->getMessage(), 'error', [
                    'package' => $package_name,
                ]);
            }
            $this->log('Checking out default branch - ' . $default_branch);
            $checkout_default_exit_code = $this->execCommand('git checkout ' . $default_branch, false);
            if ($checkout_default_exit_code) {
                $this->log($this->getLastStdErr());
                throw new \Exception('There was an error trying to check out the default branch. The process ended with exit code ' . $checkout_default_exit_code);
            }
            // Also do a git checkout of the files, since we want them in the state they were on the default branch
            $this->execCommand('git checkout .', false);
            // Re-do composer install to make output better, and to make the lock file actually be there for
            // consecutive updates, if it is a project without it.
            if (!$lock_file_contents) {
                $this->execCommand('rm composer.lock');
            }
            try {
                $this->doComposerInstall($config);
            } catch (\Throwable $e) {
                $this->log('Rolling back state on the default branch was not successful. Subsequent updates may be affected');
            }
        }
    }

    protected function handlePossibleUpdatePrScenario(\Exception $e, $branch_name, $pr_params, $prs_named)
    {
        $this->log('Had a problem with creating the pull request: ' . $e->getMessage(), 'error');
        if ($this->shouldUpdatePr($branch_name, $pr_params, $prs_named)) {
            $this->log('Will try to update the PR based on settings.');
            $this->getPrClient()->updatePullRequest($this->slug, $prs_named[$branch_name]['number'], $pr_params);
        }
    }

    /**
     * Helper function.
     */
    protected function shouldUpdatePr($branch_name, $pr_params, $prs_named)
    {
        if (empty($branch_name)) {
            return false;
        }
        if (empty($pr_params)) {
            return false;
        }
        if (!empty($prs_named[$branch_name]['title']) && $prs_named[$branch_name]['title'] != $pr_params['title']) {
            return true;
        }
        if (!empty($prs_named[$branch_name]['body']) && !empty($pr_params['body'])) {
            if (trim($prs_named[$branch_name]['body']) != trim($pr_params['body'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the messages that are logged.
     *
     * @return \eiriksm\CosyComposer\Message[]
     *   The logged messages.
     */
    public function getOutput()
    {
        $msgs = [];
        if (!$this->logger instanceof ArrayLogger) {
            return $msgs;
        }
        /** @var ArrayLogger $my_logger */
        $my_logger = $this->logger;
        foreach ($my_logger->get() as $message) {
            /** @var Message $msg */
            $msg = $message['message'];
            $msg->setContext($message['context']);
            if (isset($message['context']['command'])) {
                $msg = new Message($msg->getMessage(), Message::COMMAND);
                $msg->setContext($message['context']);
            }
            $msgs[] = $msg;
        }
        return $msgs;
    }

    /**
     * @param OutputInterface $output
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * Cleans up after the run.
     */
    private function cleanUp()
    {
        // Run composer install again, so we can get rid of newly installed updates for next run.
        $this->execCommand('composer install --no-ansi -n', false, 1200);
        $this->chdir('/tmp');
        $this->log('Cleaning up after update check.');
        $this->execCommand('rm -rf ' . $this->tmpDir, false, 300);
    }

    /**
     * Creates a title for a PR.
     *
     * @param \stdClass $item
     *   The item in question.
     *
     * @return string
     *   A string ready to use.
     */
    protected function createTitle($item, $post_update_data, $security_update = false)
    {
        $update = new ViolinistUpdate();
        $update->setName($item->name);
        $update->setCurrentVersion($item->version);
        $update->setNewVersion($post_update_data->version);
        $update->setSecurityUpdate($security_update);
        if ($item->version === $post_update_data->version) {
            // I guess we are updating the dependencies? We are surely not updating from one version to the same.
            return sprintf('Update dependencies of %s', $item->name);
        }
        return trim($this->messageFactory->getPullRequestTitle($update));
    }

  /**
   * Helper to create body.
   */
    public function createBody($item, $post_update_data, $changelog = null, $security_update = false, array $update_list = [], $changed_files = [])
    {
        $update = new ViolinistUpdate();
        $update->setName($item->name);
        $update->setCurrentVersion($item->version);
        $update->setNewVersion($post_update_data->version);
        $update->setSecurityUpdate($security_update);
        if ($changelog) {
            /** @var \Violinist\GitLogFormat\ChangeLogData $changelog */
            $update->setChangelog($changelog->getAsMarkdown());
        }
        if ($this->project && $this->project->getCustomPrMessage()) {
            $update->setCustomMessage($this->project->getCustomPrMessage());
        }
        $update->setUpdatedList($update_list);
        if ($changed_files) {
            $update->setChangedFiles($changed_files);
        }
        return $this->messageFactory->getPullRequestBody($update);
    }

    /**
     * Helper to create branch name.
     */
    protected function createBranchName($item, $one_per_package = false, $config = null)
    {

        if ($one_per_package) {
            // Add a prefix.
            $prefix = '';
            if ($config) {
                /** @var Config $config */
                $prefix = $config->getBranchPrefix();
            }
            return sprintf('%sviolinist%s', $prefix, $this->createBranchNameFromVersions($item->name, '', ''));
        }
        $name = $this->createBranchNameFromVersions($item->name, $item->version, $item->latest);
        $prefix = '';
        if ($config) {
            /** @var Config $config */
            $prefix = $config->getBranchPrefix();
        }
        return "$prefix$name";
    }

    protected function createBranchNameFromVersions($package, $version_from, $version_to)
    {
        $item_string = sprintf('%s%s%s', $package, $version_from, $version_to);
        // @todo: Fix this properly.
        $result = preg_replace('/[^a-zA-Z0-9]+/', '', $item_string);
        return $result;
    }

    /**
     * Executes a command.
     */
    protected function execCommand($command, $log = true, $timeout = 120)
    {
        $this->executer->setCwd($this->getCwd());
        return $this->executer->executeCommand($command, $log, $timeout);
    }

    /**
     * Log a message.
     *
     * @param string $message
     */
    protected function log($message, $type = 'message', $context = [])
    {

        $this->getLogger()->log('info', new Message($message, $type), $context);
    }

    protected function attachDrupalAdvisories(array &$alerts)
    {
        if (!$this->lockFileContents) {
            return;
        }
        $data = ComposerLockData::createFromString(json_encode($this->lockFileContents));
        try {
            $drupal = $data->getPackageData('drupal/core');
            // Now see if a newer version is available, and if it is a security update.
            $endpoint = 'current';
            $major_version = mb_substr($drupal->version, 0, 1);
            switch ($major_version) {
                case '8':
                    $endpoint = '8.x';
                    break;

                case '7':
                    $endpoint = '7.x';
                    break;

                case '9':
                    // Using current.
                    break;
                default:
                    throw new \Exception('No idea what endpoint to use to check for drupal security release');
            }

            $client = $this->getHttpClient();
            $url = sprintf('https://updates.drupal.org/release-history/drupal/%s', $endpoint);
            $request = new Request('GET', $url);
            $response = $client->sendRequest($request);
            $data = $response->getBody();
            $xml = @simplexml_load_string($data);
            if (!$xml) {
                return;
            }
            $known_names = [
                'drupal/core-recommended',
                'drupal/core-composer-scaffold',
                'drupal/core-project-message',
                'drupal/core',
                'drupal/drupal',
            ];
            if (empty($xml->releases->release)) {
                return;
            }
            $drupal_version_array = explode('.', $drupal->version);
            $active_branch = sprintf('%s.%s', $drupal_version_array[0], $drupal_version_array[1]);
            $supported_branches = explode(',', (string) $xml->supported_branches);
            $is_supported = false;
            foreach ($supported_branches as $branch) {
                if (strpos($branch, $active_branch) === 0) {
                    $is_supported = true;
                }
            }
            foreach ($xml->releases->release as $release) {
                if (empty($release->version)) {
                    continue;
                }
                if (empty($release->terms) || empty($release->terms->term)) {
                    continue;
                }
                $version = (string) $release->version;
                // If they are not on the same branch, then let's skip it as well.
                if ($endpoint !== '7.x') {
                    if ($is_supported && strpos($version, $active_branch) !== 0) {
                        continue;
                    }
                }
                if (version_compare($version, $drupal->version) !== 1) {
                    continue;
                }
                $is_sec = false;
                foreach ($release->terms->term as $item) {
                    $type = (string) $item->value;
                    if ($type === 'Security update') {
                        $is_sec = true;
                    }
                }
                if (!$is_sec) {
                    continue;
                }
                $this->log('Found a security update in the update XML. Will populate advisories from this, if not already set.');
                foreach ($known_names as $known_name) {
                    if (!empty($alerts[$known_name])) {
                        continue;
                    }
                    $alerts[$known_name] = [
                        'version' => $version,
                    ];
                }
                break;
            }
        } catch (\Throwable $e) {
            // Totally fine.
        }
    }

  /**
   * Does a composer install.
   *
   * @throws \eiriksm\CosyComposer\Exceptions\ComposerInstallException
   */
    protected function doComposerInstall(Config $config)
    {
        // @todo: Should probably use composer install command programmatically.
        $this->log('Running composer install');
        $run_scripts_suffix = '';
        if (!$config->shouldRunScripts()) {
            $run_scripts_suffix = ' --no-scripts';
        }
        if ($code = $this->execCommand('composer install --no-ansi -n' . $run_scripts_suffix, false, 1200)) {
            // Other status code than 0.
            $this->log($this->getLastStdOut(), Message::COMMAND);
            $this->log($this->getLastStdErr());
            throw new ComposerInstallException('Composer install failed with exit code ' . $code);
        }

        $command_output = $this->executer->getLastOutput();
        if (!empty($command_output['stderr'])) {
            $this->log($command_output['stderr'], Message::COMMAND);
        }
        $this->log('composer install completed successfully');
    }

   /**
    * Changes to a different directory.
    */
    private function chdir($dir)
    {
        if (!file_exists($dir)) {
            return false;
        }
        $this->setCWD($dir);
        return true;
    }

    protected function setCWD($dir)
    {
        $this->cwd = $dir;
    }


    /**
     * @return string
     */
    public function getTmpDir()
    {
        return $this->tmpDir;
    }

    /**
     * @param string $tmpDir
     */
    public function setTmpDir($tmpDir)
    {
        $this->tmpDir = $tmpDir;
    }

    protected function retrieveChangedFiles($package_name, $lockdata, $version_from, $version_to)
    {
        return $this->getFetcher()
            ->retrieveChangedFiles($package_name, $lockdata, $version_from, $version_to);
    }

    protected function getFetcher() : ChangelogRetriever
    {
        if ($this->fetcher) {
            return $this->fetcher;
        }
        $cosy_factory_wrapper = new ProcessFactoryWrapper();
        $cosy_factory_wrapper->setExecutor($this->executer);
        $retriever = new DependencyRepoRetriever($cosy_factory_wrapper);
        $retriever->setAuthToken($this->userToken);
        $this->fetcher = new ChangelogRetriever($retriever, $cosy_factory_wrapper);
        return $this->fetcher;
    }

    /**
     * Helper to retrieve changelog.
     */
    public function retrieveChangeLog($package_name, $lockdata, $version_from, $version_to)
    {
        $fetcher = $this->getFetcher();
        $log_obj = $fetcher->retrieveChangelog($package_name, $lockdata, $version_from, $version_to);
        $changelog_string = '';
        $json = json_decode($log_obj->getAsJson());
        foreach ($json as $item) {
            $changelog_string .= sprintf("%s %s\n", $item->hash, $item->message);
        }
        if (mb_strlen($changelog_string) > 60000) {
            // Truncate it to 60K.
            $changelog_string = mb_substr($changelog_string, 0, 60000);
            // Then split it into lines.
            $lines = explode("\n", $changelog_string);
            // Cut off the last one, since it could be partial.
            array_pop($lines);
            // Then append a line saying the changelog was too long.
            $lines[] = sprintf('%s ...more commits found, but message is too long for PR', $version_to);
            $changelog_string = implode("\n", $lines);
        }
        $log = ChangeLogData::createFromString($changelog_string);
        $lock_data_obj = new ComposerLockData();
        $lock_data_obj->setData($lockdata);
        $data = $lock_data_obj->getPackageData($package_name);
        $git_url = preg_replace('/.git$/', '', $data->source->url);
        $repo_parsed = parse_uri($git_url);
        if (!empty($repo_parsed)) {
            switch ($repo_parsed['_protocol']) {
                case 'git@github.com':
                    $git_url = sprintf('https://github.com/%s', $repo_parsed['path']);
                    break;
            }
        }
        $log->setGitSource($git_url);
        return $log;
    }

    private function getPackageData($package_name, $lockdata)
    {
        $lockfile_key = 'packages';
        $key = $this->getPackagesKey($package_name, $lockfile_key, $lockdata);
        if ($key === false) {
            // Well, could be a dev req.
            $lockfile_key = 'packages-dev';
            $key = $this->getPackagesKey($package_name, $lockfile_key, $lockdata);
            // If the key still is false, then this is not looking so good.
            if ($key === false) {
                throw new \Exception(
                    sprintf(
                        'Did not find the requested package (%s) in the lockfile. This is probably an error',
                        $package_name
                    )
                );
            }
        }
        return $lockdata->{$lockfile_key}[$key];
    }

    public static function getComposerJsonName($cdata, $name, $tmp_dir)
    {
        if (!empty($cdata->{'require-dev'}->{$name})) {
            return $name;
        }
        if (!empty($cdata->require->{$name})) {
            return $name;
        }
        // If we can not find it, we have to search through the names, and try to normalize them. They could be in the
        // wrong casing, for example.
        $possible_types = [
            'require',
            'require-dev',
        ];
        foreach ($possible_types as $type) {
            if (empty($cdata->{$type})) {
                continue;
            }
            foreach ($cdata->{$type} as $package => $version) {
                if (strtolower($package) == strtolower($name)) {
                    return $package;
                }
            }
        }
        if (!empty($cdata->extra->{"merge-plugin"})) {
            $keys = [
                'include',
                'require'
            ];
            foreach ($keys as $key) {
                if (isset($cdata->extra->{"merge-plugin"}->{$key})) {
                    foreach ($cdata->extra->{"merge-plugin"}->{$key} as $extra_json) {
                        $files = glob(sprintf('%s/%s', $tmp_dir, $extra_json));
                        if (!$files) {
                            continue;
                        }
                        foreach ($files as $file) {
                            $contents = @file_get_contents($file);
                            if (!$contents) {
                                continue;
                            }
                            $json = @json_decode($contents);
                            if (!$json) {
                                continue;
                            }
                            try {
                                return self::getComposerJsonName($json, $name, $tmp_dir);
                            } catch (\Exception $e) {
                              // Fine.
                            }
                        }
                    }
                }
            }
        }
        throw new \Exception('Could not find ' . $name . ' in composer.json.');
    }

    private function getPackagesKey($package_name, $lockfile_key, $lockdata)
    {
        $names = array_column($lockdata->{$lockfile_key}, 'name');
        return array_search($package_name, $names);
    }

    /**
     * @param Slug $slug
     *
     * @return ProviderInterface
     */
    private function getClient(Slug $slug)
    {
        if (!$this->providerFactory) {
            $this->setProviderFactory(new ProviderFactory());
        }
        return $this->providerFactory->createFromHost($slug, $this->urlArray);
    }

    /**
     * @return ProviderInterface
     */
    private function getPrClient()
    {
        if ($this->isPrivate) {
            return $this->privateClient;
        }
        $this->preparePrClient();
        $this->client->authenticate($this->userToken, null);
        return $this->client;
    }

    private function preparePrClient()
    {
        if (!$this->isPrivate) {
            if (!$this->client instanceof PublicGithubWrapper) {
                $this->client = new PublicGithubWrapper(new Client());
            }
            $this->client->setUserToken($this->userToken);
            $this->client->setUrlFromTokenUrl($this->tokenUrl);
            $this->client->setProject($this->project);
        }
    }
}
