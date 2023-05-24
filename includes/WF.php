<?php

namespace WebFramework\Core;

use Cache\Adapter\Redis\RedisCachePool;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use WebFramework\Security\AuthenticationService;
use WebFramework\Security\BlacklistService;
use WebFramework\Security\ConfigService as SecureConfigService;
use WebFramework\Security\CsrfService;
use WebFramework\Security\ProtectService;

class WF
{
    private string $app_dir = '';

    private static WF $framework;
    private static Database $main_db;       // Only for DataCore and StoredValues abstraction
    private static Cache $static_cache;     // Only for DataCore and StoredValues abstraction

    protected bool $initialized = false;
    private Database $main_database;

    /**
     * @var array<Database>
     */
    private array $aux_databases = [];
    private Cache $cache;

    // Services
    //
    protected ?AssertService $assert_service = null;
    protected ?BaseFactory $base_factory = null;
    protected ?AuthenticationService $authentication_service = null;
    protected ?BlacklistService $blacklist_service = null;
    protected ?BrowserSessionService $browser_session_service = null;
    protected ?ConfigService $config_service = null;
    protected ?CsrfService $csrf_service = null;
    protected ?DatabaseManager $database_manager = null;
    protected ?DebugService $debug_service = null;
    protected ?LatteRenderService $latte_render_service = null;
    protected ?MailService $mail_service = null;
    protected ?MessageService $message_service = null;
    protected ?PostmarkClientFactory $postmark_client_factory = null;
    protected ?ReportFunction $report_function = null;
    protected ?ResponseEmitter $response_emitter = null;
    protected ?ResponseFactory $response_factory = null;
    protected ?SecureConfigService $secure_config_service = null;
    protected ?ProtectService $protect_service = null;
    protected ?UserMailer $user_mailer = null;
    protected ?ValidatorService $validator_service = null;

    /**
     * @var array<string>
     */
    private array $configs = [
        '/vendor/avoutic/web-framework/includes/BaseConfig.php',
        '/includes/config.php',
        '?/includes/config_local.php',
    ];

    private bool $check_db = true;
    private bool $check_app_db_version = true;
    private bool $check_wf_db_version = true;

    public function __construct(
        private ContainerInterface $container
    ) {
        $this->cache = $this->container->get(Cache::class);
        self::$static_cache = $this->cache;

        // Determine app dir
        //
        $reflection = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
        $this->app_dir = dirname($reflection->getFileName(), 3);
    }

    public function get_assert_service(): AssertService
    {
        return $this->container->get(AssertService::class);
    }

    public function get_authentication_service(): AuthenticationService
    {
        return $this->container->get(AuthenticationService::class);
    }

    public function get_base_factory(): BaseFactory
    {
        return $this->container->get(BaseFactory::class);
    }

    public function get_blacklist_service(): BlacklistService
    {
        return $this->container->get(BlacklistService::class);
    }

    public function get_browser_session_service(): BrowserSessionService
    {
        return $this->container->get(BrowserSessionService::class);
    }

    public function get_config_service(): ConfigService
    {
        return $this->container->get(ConfigService::class);
    }

    public function get_csrf_service(): CsrfService
    {
        return $this->container->get(CsrfService::class);
    }

    public function get_database_manager(): DatabaseManager
    {
        return $this->container->get(DatabaseManager::class);
    }

    public function get_debug_service(): DebugService
    {
        return $this->container->get(DebugService::class);
    }

    public function get_report_function(): ReportFunction
    {
        return $this->container->get(ReportFunction::class);
    }

    public function get_response_emitter(): ResponseEmitter
    {
        return $this->container->get(ResponseEmitter::class);
    }

    public function get_response_factory(): ResponseFactory
    {
        return $this->container->get(ResponseFactory::class);
    }

    public function get_secure_config_service(): SecureConfigService
    {
        return $this->container->get(SecureConfigService::class);
    }

    public function get_protect_service(): ProtectService
    {
        return $this->container->get(ProtectService::class);
    }

    public function get_user_mailer(): UserMailer
    {
        return $this->container->get(UserMailer::class);
    }

    public function get_validator_service(): ValidatorService
    {
        return $this->container->get(ValidatorService::class);
    }

    public function get_latte_render_service(): LatteRenderService
    {
        return $this->container->get(LatteRenderService::class);
    }

    public function get_mail_service(): MailService
    {
        return $this->container->get(MailService::class);
    }

    public function get_message_service(): MessageService
    {
        return $this->container->get(MessageService::class);
    }

    public function get_postmark_client_factory(): PostmarkClientFactory
    {
        return $this->container->get(PostmarkClientFactory::class);
    }

    public static function assert_handler(string $file, int $line, string $message, string $error_type): void
    {
        @trigger_error('Deprecated. Will be removed', E_USER_DEPRECATED);
        $framework = self::get_framework();
        $framework->internal_assert_handler($message, $error_type);
    }

    public function internal_assert_handler(string $message, string $error_type): void
    {
        @trigger_error('Deprecated. Will be removed', E_USER_DEPRECATED);
        $assert_service = $this->get_assert_service();
        $assert_service->report_error(message: $message, error_type: $error_type);
    }

    public static function verify(bool|int $bool, string $message): void
    {
        $framework = self::get_framework();
        $framework->internal_verify($bool, $message);
    }

    public function internal_verify(bool|int $bool, string $message): void
    {
        if ($bool)
        {
            return;
        }

        $assert_service = $this->get_assert_service();
        $assert_service->verify($bool, $message);
    }

    // Send a triggered error message but continue running
    //
    /**
     * @param array<mixed> $stack
     */
    public static function report_error(string $message, array $stack = []): void
    {
        $framework = self::get_framework();
        $framework->internal_report_error($message, $stack);
    }

    /**
     * @param array<mixed> $stack
     */
    public function internal_report_error(string $message, array $stack = []): void
    {
        $request = ServerRequestFactory::createFromGlobals();

        $assert_service = $this->get_assert_service();
        $assert_service->report_error($message, $stack, $request);
    }

    public static function blacklist_verify(bool|int $bool, string $reason, int $severity = 1): void
    {
        $framework = self::get_framework();
        $framework->internal_blacklist_verify($bool, $reason, $severity);
    }

    public function internal_blacklist_verify(bool|int $bool, string $reason, int $severity = 1): void
    {
        if ($bool)
        {
            return;
        }

        $this->add_blacklist_entry($reason, $severity);

        exit();
    }

    public function add_blacklist_entry(string $reason, int $severity = 1): void
    {
        $ip = (isset($_SERVER['REMOTE_ADDR'])) ? $_SERVER['REMOTE_ADDR'] : 'app';

        $user_id = null;
        if ($this->is_authenticated())
        {
            $user = $this->get_authentication_service()->get_authenticated_user();
            $user_id = $user->id;
        }

        $this->get_blacklist_service()->add_entry($ip, $user_id, $reason, $severity);
    }

    public static function shutdown_handler(): void
    {
        @trigger_error('Deprecated. Will be removed', E_USER_DEPRECATED);
        $framework = self::get_framework();
        $framework->internal_shutdown_handler();
    }

    public function internal_shutdown_handler(): void
    {
        $last_error = error_get_last();
        if (!$last_error)
        {
            return;
        }

        $message = "{$last_error['file']}:{$last_error['line']}:{$last_error['message']}";

        $assert_service = $this->get_assert_service();
        $assert_service->report_error(message: $message, error_type: (string) $last_error['type']);

        // Don't trigger other handlers after this call
        //
        exit();
    }

    protected function exit_error(string $short_message, string $message): void
    {
        echo('Fatal error: '.$short_message.PHP_EOL);
        echo($message.PHP_EOL);

        exit();
    }

    public static function get_framework(): self
    {
        return self::$framework;
    }

    public static function get_app_dir(): string
    {
        $framework = self::get_framework();

        return $framework->internal_get_app_dir();
    }

    public function internal_get_app_dir(): string
    {
        return $this->app_dir;
    }

    public static function get_config(string $location = ''): mixed
    {
        $framework = self::get_framework();

        return $framework->get_config_service()->get($location);
    }

    public function internal_get_config(string $location = ''): mixed
    {
        @trigger_error('Deprecated. Directly call ConfigService instead', E_USER_DEPRECATED);

        return $this->get_config_service()->get($location);
    }

    public function get_db(string $tag = ''): Database
    {
        if (!strlen($tag))
        {
            return $this->main_database;
        }

        $this->internal_verify(array_key_exists($tag, $this->aux_databases), 'Database not registered');

        return $this->aux_databases[$tag];
    }

    // Only relevant for DataCore and StoredValues to retrieve main database in static functions
    //
    public static function get_main_db(): Database
    {
        return self::$main_db;
    }

    public function get_cache(): Cache
    {
        return $this->cache;
    }

    // Only relevant for DataCore and StoredValues to retrieve main database in static functions
    //
    public static function get_static_cache(): Cache
    {
        return self::$static_cache;
    }

    public function skip_db_check(): void
    {
        $this->check_db = false;
    }

    public function skip_app_db_version_check(): void
    {
        $this->check_app_db_version = false;
    }

    public function skip_wf_db_version_check(): void
    {
        $this->check_wf_db_version = false;
    }

    /**
     * @param array<string> $configs Config files to merge on top of each other in order.
     *                               File locations should be relative to the app dir
     *                               including leading /. If it starts with a '?' the file
     *                               does not have to be present.
     */
    public function set_configs(array $configs): void
    {
        $this->configs = $configs;
    }

    public function init(): void
    {
        // Make sure static wrapper functions can work
        //
        self::$framework = $this;

        mt_srand();

        $this->check_file_requirements();

        // Enable debugging if requested
        //
        if ($this->get_config_service()->get('debug') == true)
        {
            error_reporting(E_ALL | E_STRICT);
            ini_set('display_errors', '1');
        }
        else
        {
            register_shutdown_function([$this, 'internal_shutdown_handler']);
        }

        // Set default timezone
        //
        date_default_timezone_set($this->get_config_service()->get('timezone'));

        $this->check_config_requirements();
        $this->load_requirements();

        if ($this->get_config_service()->get('database_enabled') == true)
        {
            $this->init_databases();
        }

        $this->check_compatibility();

        $this->init_cache();

        $this->initialized = true;
    }

    private function check_file_requirements(): void
    {
        foreach ($this->configs as $config_file)
        {
            // Skip optional files
            if ($config_file[0] == '?')
            {
                continue;
            }

            if (!is_file("{$this->app_dir}{$config_file}"))
            {
                $this->exit_error(
                    'Missing base requirement',
                    "One of the required files ({$config_file}) is not found on the server."
                );
            }
        }
    }

    private function load_requirements(): void
    {
        // Check for special loads before anything else
        //
        if ($this->get_config_service()->get('preload') == true)
        {
            if (!file_exists("{$this->app_dir}/includes/preload.inc.php"))
            {
                $this->exit_error(
                    'Preload indicated but not present',
                    'The file "includes/preload.inc.php" does not exist.'
                );
            }

            require_once "{$this->app_dir}/includes/preload.inc.php";
        }

        // Load global and site specific defines
        //
        require_once __DIR__.'/defines.inc.php';
    }

    private function check_config_requirements(): void
    {
        // Check for required values
        //
        if (!strlen($this->get_config_service()->get('sender_core.default_sender')))
        {
            $this->exit_error(
                'No default sender specified',
                'One of the required config values (sender_core.default_sender) is missing. '.
                'Required for mailing verify information'
            );
        }

        if (!strlen($this->get_config_service()->get('sender_core.assert_recipient')))
        {
            $this->exit_error(
                'No assert recipient specified',
                'One of the required config values (sender_core.assert_recipient) is missing. '.
                'Required for mailing verify information'
            );
        }

        if (strlen($this->get_config_service()->get('security.hmac_key')) < 20)
        {
            $this->exit_error(
                'Required config value missing',
                'No or too short HMAC Key provided (Minimum 20 chars) in (security.hmac_key).'
            );
        }

        if (strlen($this->get_config_service()->get('security.crypt_key')) < 20)
        {
            $this->exit_error(
                'Required config value missing',
                'No or too short Crypt Key provided (Minimum 20 chars) in (security.crypt_key).'
            );
        }
    }

    public function init_databases(): void
    {
        // Start the database connection(s)
        //
        $main_db_tag = $this->get_config_service()->get('database_config');
        $main_config = $this->get_secure_config_service()->get_auth_config('db_config.'.$main_db_tag);

        $mysql = new \mysqli(
            $main_config['database_host'],
            $main_config['database_user'],
            $main_config['database_password'],
            $main_config['database_database']
        );

        if ($mysql->connect_error)
        {
            $this->exit_error(
                'Database server connection failed',
                'The connection to the database server failed.'
            );
        }

        $this->main_database = new MysqliDatabase($mysql);
        self::$main_db = $this->main_database;

        // Open auxilary database connections
        //
        foreach ($this->get_config_service()->get('databases') as $tag)
        {
            $tag_config = $this->get_secure_config_service()->get_auth_config('db_config.'.$tag);

            $mysql = new \mysqli(
                $tag_config['database_host'],
                $tag_config['database_user'],
                $tag_config['database_password'],
                $tag_config['database_database']
            );

            if ($mysql->connect_error)
            {
                $this->exit_error(
                    "Database server connection for '{$tag}' failed",
                    'The connection to the database server failed.'
                );
            }

            $this->aux_databases[$tag] = new MysqliDatabase($mysql);
        }
    }

    public function check_compatibility(): void
    {
        // Verify all versions for compatibility
        //
        $required_wf_version = FRAMEWORK_VERSION;
        $supported_wf_version = $this->get_config_service()->get('versions.supported_framework');

        if ($supported_wf_version == -1)
        {
            $this->exit_error(
                'No supported Framework version configured',
                'There is no supported framework version provided in "versions.supported_framework". '.
                "The current version is {$required_wf_version} of this Framework."
            );
        }

        if ($required_wf_version != $supported_wf_version)
        {
            $this->exit_error(
                'Framework version mismatch',
                'Please make sure that this app is upgraded to support version '.
                "{$required_wf_version} of this Framework."
            );
        }

        if ($this->get_config_service()->get('database_enabled') != true || !$this->check_db)
        {
            return;
        }

        $required_wf_db_version = FRAMEWORK_DB_VERSION;
        $required_app_db_version = $this->get_config_service()->get('versions.required_app_db');

        // Check if base table is present
        //
        if (!$this->main_database->table_exists('config_values'))
        {
            $this->exit_error(
                'Database missing config_values table',
                'Please make sure that the core Framework database scheme has been applied. (by running db_init script)'
            );
        }

        $stored_values = new StoredValues($this->get_main_db(), 'db');
        $current_wf_db_version = $stored_values->get_value('wf_db_version', '0');
        $current_app_db_version = $stored_values->get_value('app_db_version', '1');

        if ($this->check_wf_db_version && $required_wf_db_version != $current_wf_db_version)
        {
            $this->exit_error(
                'Framework Database version mismatch',
                'Please make sure that the latest Framework database changes for version '.
                "{$required_wf_db_version} of the scheme are applied."
            );
        }

        if ($this->check_app_db_version && $required_app_db_version > 0 && $current_app_db_version == 0)
        {
            $this->exit_error(
                'No app DB present',
                'Config (versions.required_app_db) indicates an App DB should be present. None found.'
            );
        }

        if ($this->check_app_db_version && $required_app_db_version > $current_app_db_version)
        {
            $this->exit_error(
                'Outdated version of the app DB',
                "Please make sure that the app DB scheme is at least {$required_app_db_version}. (Current: {$current_app_db_version})"
            );
        }
    }

    private function init_cache(): void
    {
        if ($this->get_config_service()->get('cache_enabled') == true)
        {
            // Start the Redis cache connection
            //
            $cache_config = $this->get_secure_config_service()->get_auth_config('redis');

            $redis_client = new \Redis();
            $result = $redis_client->pconnect(
                $cache_config['hostname'],
                $cache_config['port'],
                1,
                'wf',
                0,
                0,
                ['auth' => $cache_config['password']]
            );

            if ($result !== true)
            {
                $this->exit_error(
                    'Cache connection failed',
                    '',
                );
            }

            $cache_pool = new RedisCachePool($redis_client);

            try
            {
                // Workaround: Without trying to check something, the connection is not yet verified.
                //
                $cache_pool->hasItem('errors');
            }
            catch (\Throwable $e)
            {
                $this->exit_error(
                    'Cache connection failed',
                    '',
                );
            }
            $this->cache = new RedisCache($cache_pool);

            self::$static_cache = $this->cache;
        }
    }

    // Deprecated (Remove for v6)
    //
    public function get_sanity_check(): SanityCheckInterface
    {
        @trigger_error('WF->get_sanity_check()', E_USER_DEPRECATED);
        $class_name = $this->get_config_service()->get('sanity_check_module');

        return $this->instantiate_sanity_check($class_name);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function instantiate_sanity_check(string $class_name, array $config = []): SanityCheckInterface
    {
        $this->verify(class_exists($class_name), "Sanity check module '{$class_name}' not found");

        $obj = new $class_name($config);
        $this->internal_verify($obj instanceof SanityCheckInterface, 'Sanity check module does not implement SanityCheckInterface');

        return $obj;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_sanity_checks_to_run(): array
    {
        $class_name = $this->get_config_service()->get('sanity_check_module');
        $class_names = $this->get_config_service()->get('sanity_check_modules');

        if (strlen($class_name))
        {
            @trigger_error('Config sanity_check_module', E_USER_DEPRECATED);

            $class_names[$class_name] = [];
        }

        return $class_names;
    }

    public function check_sanity(): bool
    {
        $class_names = $this->get_sanity_checks_to_run();
        if (!count($class_names))
        {
            return true;
        }

        $stored_values = new StoredValues($this->get_main_db(), 'sanity_check');
        $build_info = $this->get_build_info();
        $commit = $build_info['commit'];

        if ($commit == null)
        {
            // We are in live code. Prevent flooding. Only start check once per
            // five seconds.
            //
            $last_timestamp = (int) $stored_values->get_value('last_check', '0');

            if (time() - $last_timestamp < 5)
            {
                return true;
            }

            $stored_values->set_value('last_check', (string) time());
        }
        else
        {
            // Only check if this commit was not yet successfully checked
            //
            $checked = $stored_values->get_value('checked_'.$commit, '0');
            if ($checked !== '0')
            {
                return true;
            }
        }

        foreach ($class_names as $class_name => $module_config)
        {
            $sanity_check = $this->instantiate_sanity_check($class_name, $module_config);
            $result = $sanity_check->perform_checks();

            $this->verify($result, 'Sanity check failed');
        }

        // Register successful check of this commit
        //
        if ($commit !== null)
        {
            $stored_values->set_value('checked_'.$commit, '1');
        }

        return true;
    }

    public function is_authenticated(): bool
    {
        @trigger_error('Deprecated. Will be removed', E_USER_DEPRECATED);

        return $this->get_authentication_service()->is_authenticated();
    }

    public function authenticate(User $user): void
    {
        @trigger_error('Deprecated. Will be removed', E_USER_DEPRECATED);
        $this->get_authentication_service()->authenticate($user);
    }

    public function deauthenticate(): void
    {
        @trigger_error('Deprecated. Will be removed', E_USER_DEPRECATED);
        $this->get_authentication_service()->deauthenticate();
    }

    public function invalidate_sessions(int $user_id): void
    {
        @trigger_error('Deprecated. Will be removed', E_USER_DEPRECATED);
        $this->get_authentication_service()->invalidate_sessions($user_id);
    }

    public function get_authenticated_user(): User
    {
        @trigger_error('Deprecated. Will be removed', E_USER_DEPRECATED);

        return $this->get_authentication_service()->get_authenticated_user();
    }

    /**
     * @param array<string> $permissions
     */
    public function user_has_permissions(array $permissions): bool
    {
        @trigger_error('Deprecated. Will be removed', E_USER_DEPRECATED);

        return $this->get_authentication_service()->user_has_permissions($permissions);
    }

    /**
     * Get build info.
     *
     * @return array{commit: null|string, timestamp: string}
     */
    public function get_build_info(): array
    {
        @trigger_error('Deprecated. Will be removed', E_USER_DEPRECATED);

        return $this->get_debug_service()->get_build_info();
    }
}
