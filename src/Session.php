<?php
namespace Rikvdh;

use Illuminate\Database\Capsule\Manager as LaravelDb;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\Store;
use RuntimeException;

class Session
{
    /** @var string $name Used to identify the session, the name of the actual session cookie. */
    protected $name = 'my-session';

    /** @var int $timeout The session timeout in minutes */
    protected $timeout = 60 * 24;

    /** @var string $path This is passed directly to setcookie. */
    protected $path = '/';

    /** @var string $domain This is passed directly to setcookie. */
    protected $domain;

    /** @var bool $secure This is passed directly to setcookie. */
    protected $secure = false;

    /** @var string $table The name of the database table to use for session storage. */
    protected $table = 'sessions';

    /** @var \Illuminate\Database\Connection $dbConnection database connection */
    protected $dbConnection;

    /** @var \Illuminate\Session\Store $sessionStore session storage */
    protected $sessionStore;

    /**
     * Property: instance
     * =========================================================================
     * This is used as part of the globalise functionality.
     */
    private static $instance;

    /**
     * This is where we set all our defaults. If you need to customise this
     * container this is a good place to look to see what can be configured
     * and how to configure it.
     */
    public function __construct($dbConfig, $options = null)
    {
        foreach ($options as $option => $v) {
            $this->{$option} = $v;
        }
        $capsule = new LaravelDb;
        $capsule->addConnection($dbConfig);
        $this->dbConnection = $capsule->getConnection('default');

        $sessHandler = new DatabaseSessionHandler($this->dbConnection, $this->table, $this->timeout);
        $this->sessionStore = new Store($this->name, $sessHandler);

        // Make sure we have a sessions table
        $schema = $this->dbConnection->getSchemaBuilder();
        if (!$schema->hasTable($this->table)) {
            $schema->create($this->table, function ($t) {
                $t->string('id')->unique();
                $t->text('payload');
                $t->integer('last_activity');
            });
        }

        // Run the garbage collection
        $this->sessionStore->getHandler()->gc($this->timeout * 60);

        // Check for our session cookie
        if (isset($_COOKIE[$this->name])) {
            // Grab the session id from the cookie
            $cookie_id = $_COOKIE[$this->name];
            $this->sessionStore->setId($cookie_id);
        }

        // Set / reset the session cookie
        if (!isset($_COOKIE[$this->name])) {
            setcookie(
                $this->name,
                $this->sessionStore->getId(),
                0,
                $this->path,
                $this->domain,
                $this->secure,
                true
            );
        }

        // Start the session
        $this->sessionStore->start();

        // Save the session on shutdown
        register_shutdown_function([$this->sessionStore, 'save']);
        $this->globalise();
    }

    /**
     * Method: regenerate
     * =========================================================================
     * When the session id is regenerated we need to reset the cookie.
     *
     * Parameters:
     * -------------------------------------------------------------------------
     * - $destroy: If set to true the previous session will be deleted.
     *
     * Returns:
     * -------------------------------------------------------------------------
     * boolean
     */
    public function regenerate($destroy = false)
    {
        if ($this->sessionStore->regenerate($destroy)) {
            setcookie(
                $this->sessionStore->getName(),
                $this->sessionStore->getId(),
                0,
                $this->path,
                $this->domain,
                $this->secure,
                true
            );
            return true;
        } else {
            return false;
        }
    }

    /**
     * Method: globalise
     * =========================================================================
     * Now in a normal laravel application you can call the session api like so:
     *
     * ```php
     * Session::push('key', 'value');
     * ```
     *
     * This is because laravel has the IoC container with Service Providers and
     * Facades and other intresting things that work some magic to set this up
     * for you. Have a look in you main app.php config file and checkout the
     * aliases section.
     *
     * If you want to be able to do the same in your
     * application you need to call this method.
     *
     * Parameters:
     * -------------------------------------------------------------------------
     * - $alias: This is the name of the alias to create. Defaults to Session.
     *
     * Returns:
     * -------------------------------------------------------------------------
     * void
     *
     * Throws:
     * -------------------------------------------------------------------------
     * - RuntimeException: When a class of the same name as the alias
     *   already exists.
     */
    public function globalise($alias = 'Session')
    {
        // Create the alias name
        if (substr($alias, 0, 1) != '\\') {
            // This ensures the alias is created in the global namespace.
            $alias = '\\' . $alias;
        }

        // Check if a class already exists
        if (class_exists($alias)) {
            // Bail out, a class already exists with the same name.
            throw new RuntimeException('Class already exists!');
        }

        // Create the alias
        class_alias('\Rikvdh\Session', $alias);

        // Save our instance
        self::$instance = $this;
    }

    /**
     * This will pass any unresolved method calls
     * through to the main session store object.
     *
     * @param string $name The name of the method to call.
     * @param mixed  $args The argumnent array that is given to us.
     * @return mixed whatever the function returns
     */
    public function __call($name, $args)
    {
        return call_user_func_array([$this->sessionStore, $name], $args);
    }

    /**
     * This will pass any unresolved static method calls
     * through to the saved instance.
     *
     * @param string $name The name of the method to call.
     * @param mixed  $args The argumnent array that is given to us.
     * @return mixed whatever the function returns
     */
    public static function __callStatic($name, $args)
    {
        return call_user_func_array([self::$instance, $name], $args);
    }
}
