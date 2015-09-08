<?php

namespace Wordpress\Installer\Console;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use ZipArchive;
use RuntimeException;
use GuzzleHttp\Client;
use GuzzleHttp\Subscriber\Progress\Progress;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class NewCommand extends Command
{
    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var InputInterface
     */
    protected $input;
    protected $helper;

    /**
     * Configure the command options.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Create a new Wordpress installation.')
            ->addArgument('name', InputArgument::REQUIRED)
            ->addOption('no-setup', null, InputOption::VALUE_NONE);
    }

    /**
     * Execute the command.
     *
     * @param  InputInterface  $input
     * @param  OutputInterface  $output
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;
        $this->helper = $this->getHelper('question');
        $this->verifyApplicationDoesntExist(
            $directory = getcwd().'/'.$this->input->getArgument('name'),
            $output
        );

        $this->output->writeln('<info>Downloading Wordpress...</info>');

        $this->download($zipFile = $this->makeFilename())
             ->extract($zipFile, $directory)
             ->cleanUp($zipFile);

        $this->output->writeln('<comment>Wordpress is ready!</comment>');

        if(!$this->input->getOption('no-setup')) {
            $this->setup();
        }
    }

    /**
     * Verify that the application does not already exist.
     *
     * @param  string  $directory
     * @return void
     */
    protected function verifyApplicationDoesntExist($directory, OutputInterface $output)
    {
        if (is_dir($directory)) {
            throw new RuntimeException('Wordpress already exists!');
        }
    }

    /**
     * Generate a random temporary filename.
     *
     * @return string
     */
    protected function makeFilename()
    {
        return getcwd().'/wordpress_'.md5(time().uniqid()).'.zip';
    }

    /**
     * Download the temporary Zip to the given file.
     *
     * @param  string  $zipFile
     * @return $this
     */
    protected function download($zipFile)
    {
        $uploadProgress = function() {};
        $progressBar = new ProgressBar($this->output, 100);
        $progressBar->start();
        $downloadProgress = function($expected, $total, $client, $request, $res) use ($progressBar) {
            $progressBar->setProgress(floor(100 * ($total / $expected)));
        };
        $progress = new Progress($uploadProgress, $downloadProgress);
        $response = (new Client)->get('https://wordpress.org/latest.zip', ['subscribers' => [$progress]]);
        $progressBar->finish();
        $this->output->writeln('');

        file_put_contents($zipFile, $response->getBody());

        return $this;
    }

    /**
     * Extract the zip file into the given directory.
     *
     * @param  string  $zipFile
     * @param  string  $directory
     * @return $this
     */
    protected function extract($zipFile, $directory)
    {
        $this->output->writeln('<info>Extracting Wordpress into "'.$this->input->getArgument('name').'"</info>');

        $archive = new ZipArchive;

        $archive->open($zipFile);

        $archive->extractTo($directory . '_tmp');

        $archive->close();

        rename($directory . '_tmp/wordpress', $directory);

        rmdir($directory . '_tmp');

        return $this;
    }

    /**
     * Clean-up the Zip file.
     *
     * @param  string  $zipFile
     * @return $this
     */
    protected function cleanUp($zipFile)
    {
        $this->output->writeln('<info>Cleaning files...</info>');

        @chmod($zipFile, 0777);

        @unlink($zipFile);

        return $this;
    }

    /**
     * Start the setup of Wordpress
     */
    protected function setup()
    {
        $confirmation = new ConfirmationQuestion('Do you want to set up Wordpress now? <comment>[<info>yes</info>/no]</comment> ');
        if($this->helper->ask($this->input, $this->output, $confirmation)) {
            $this->output->writeln('Starting Wordpress set up...');

            define('WP_INSTALLING', true);
            define('WP_SETUP_CONFIG', true);

            define( 'ABSPATH', getcwd() . '/'.$this->input->getArgument('name').'/' );

            require_once( ABSPATH . 'wp-settings.php' );
            require_once( ABSPATH . '/wp-admin/includes/upgrade.php' );

            $success = $this->setupDatabase();

            $this->createConfig();

            if($success) {
                $this->installWordpress();
            }
        }
    }

    protected function setupDatabase() {
        global $table_prefix;

        $dbname = new Question('Please enter the name of the database (<comment>default:</comment> <info>wordpress</info>): ', 'wordpress');
        $dbuser = new Question('Please enter the name of the database user (<comment>default:</comment> <info>homestead</info>): ', 'homestead');
        $dbpass = new Question('Please enter the database password (<comment>default:</comment> <info>secret</info>): ', 'secret');
        $dbhost = new Question('Please enter the database host (<comment>default:</comment> <info>localhost</info>): ', 'localhost');
        $dbpref = new Question('Please enter the table prefix (<comment>default:</comment> <info>wp_</info>): ', 'wp_');

        $dbname = $this->helper->ask($this->input, $this->output, $dbname);
        $dbuser = $this->helper->ask($this->input, $this->output, $dbuser);
        $dbpass = $this->helper->ask($this->input, $this->output, $dbpass);
        $dbhost = $this->helper->ask($this->input, $this->output, $dbhost);
        $table_prefix = $this->helper->ask($this->input, $this->output, $dbpref);

        $success = $this->testDatabaseConnection($dbuser, $dbpass, $dbname, $dbhost, $table_prefix);

        if(!$success) {
            return $this->setupDatabase();
        }

        define('DB_NAME', $dbname);
        define('DB_USER', $dbuser);
        define('DB_PASSWORD', $dbpass);
        define('DB_HOST', $dbhost);

        return true;
    }

    /**
     * Check if we can connect to the database
     *
     * @return bool
     */
    protected function testDatabaseConnection($dbuser, $dbpass, $dbname, $dbhost, $table_prefix) {
        $wpdb = new \wpdb( $dbuser, $dbpass, $dbname, $dbhost );
        $wpdb->db_connect();
        $wpdb->prefix = $table_prefix;
        $wpdb->base_prefix = $table_prefix;

        foreach($wpdb->tables as $table) {
            $wpdb->$table = $table_prefix.$table;
        }

        foreach($wpdb->global_tables as $table) {
            $wpdb->$table = $table_prefix.$table;
        }

        $GLOBALS['wpdb'] = $wpdb;

        $success = empty($wpdb->error);

        if ( ! $success )
            $this->output->writeln('<error>Database information incorrect!</error>');
        else
            $this->output->writeln('<info>Database information correct!</info>');

        return $success;
    }

    /**
     * Run the install scripts for Wordpress, initializing the database.
     *
     * @return $this
     */
    protected function installWordpress()
    {
        $url = new Question('Please enter the site URL: ');
        $blogTitle = new Question('Please enter the blog title: ');
        $username = new Question('Please enter your username: (<comment>dafault:</comment> <info>admin</info>)', 'admin');
        $password = new Question('Please enter your password: ');
        $email = new Question('Please enter the administrator email: ');

        $url = $this->helper->ask($this->input, $this->output, $url);
        $blogTitle = $this->helper->ask($this->input, $this->output, $blogTitle);
        $username = $this->helper->ask($this->input, $this->output, $username);
        $password = $this->helper->ask($this->input, $this->output, $password);
        $email = $this->helper->ask($this->input, $this->output, $email);

        // Set the site URL, since we cannot guess it in command line.
        define('WP_SITEURL', $url);

        // Run the actual Wordpress install function
        wp_install($blogTitle, $username, $email, false, '', $password);
        
        return $this;
    }

    /**
     * Create the wp-config.php file with the correct information.
     *
     * @return $this
     */
    protected function createConfig()
    {
        global $table_prefix, $wpdb;
        $config_file = file( ABSPATH . 'wp-config-sample.php' );
        $secret_keys = wp_remote_get( 'https://api.wordpress.org/secret-key/1.1/salt/' );

        $secret_keys = explode( "\n", wp_remote_retrieve_body( $secret_keys ) );
        foreach ( $secret_keys as $k => $v ) {
            $secret_keys[$k] = substr( $v, 28, 64 );
        }

        $key = 0;
        // Not a PHP5-style by-reference foreach, as this file must be parseable by PHP4.
        foreach ( $config_file as $line_num => $line ) {
            if ( '$table_prefix  =' == substr( $line, 0, 16 ) ) {
                $config_file[ $line_num ] = '$table_prefix  = \'' . addcslashes( $table_prefix, "\\'" ) . "';\r\n";
                continue;
            }

            if ( ! preg_match( '/^define\(\'([A-Z_]+)\',([ ]+)/', $line, $match ) )
                continue;

            $constant = $match[1];
            $padding  = $match[2];

            switch ( $constant ) {
                case 'DB_NAME'     :
                case 'DB_USER'     :
                case 'DB_PASSWORD' :
                case 'DB_HOST'     :
                    $config_file[ $line_num ] = "define('" . $constant . "'," . $padding . "'" . addcslashes( constant( $constant ), "\\'" ) . "');\r\n";
                    break;
                case 'DB_CHARSET'  :
                    if ( 'utf8mb4' === $wpdb->charset || ( ! $wpdb->charset && $wpdb->has_cap( 'utf8mb4' ) ) ) {
                        $config_file[ $line_num ] = "define('" . $constant . "'," . $padding . "'utf8mb4');\r\n";
                    }
                    break;
                case 'AUTH_KEY'         :
                case 'SECURE_AUTH_KEY'  :
                case 'LOGGED_IN_KEY'    :
                case 'NONCE_KEY'        :
                case 'AUTH_SALT'        :
                case 'SECURE_AUTH_SALT' :
                case 'LOGGED_IN_SALT'   :
                case 'NONCE_SALT'       :
                    $config_file[ $line_num ] = "define('" . $constant . "'," . $padding . "'" . $secret_keys[$key++] . "');\r\n";
                    break;
            }
        }
        unset( $line );

        /*
		 * If this file doesn't exist, then we are using the wp-config-sample.php
		 * file one level up, which is for the develop repo.
		 */
        if ( file_exists( ABSPATH . 'wp-config-sample.php' ) )
            $path_to_wp_config = ABSPATH . 'wp-config.php';
        else
            $path_to_wp_config = dirname( ABSPATH ) . '/wp-config.php';

        $handle = fopen( $path_to_wp_config, 'w' );
        foreach( $config_file as $line ) {
            fwrite( $handle, $line );
        }
        fclose( $handle );
        chmod( $path_to_wp_config, 0666 );

        return $this;
    }
}
