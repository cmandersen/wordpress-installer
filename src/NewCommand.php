<?php

namespace Wordpress\Installer\Console;

use GuzzleHttp\Client;
use GuzzleHttp\Subscriber\Progress\Progress;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;
use ZipArchive;

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
    protected $wp;

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
        $this->wp = preg_replace('~wordpress$~', '', get_included_files()[0])."vendor/bin/wp";
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
            $confirmation = new ConfirmationQuestion('Do you want to set up Wordpress now? <comment>[<info>yes</info>/no]</comment> ');
            if($this->helper->ask($this->input, $this->output, $confirmation)) {
                $this->createConfig()
                     ->installWordpress();
            }

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

    protected function createConfig() {
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

        $process = new Process("{$this->wp} core config --dbname={$dbname} --dbuser={$dbuser} --dbpass={$dbpass} --dbhost={$dbhost} --dbprefix={$table_prefix}", $this->input->getArgument('name'));
        $process->run();

        if(!$process->isSuccessful()) {
            $this->output->writeln('<error>'.$process->getErrorOutput().'</error>');

            return $this->createConfig();
        }

        return $this;
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

        $process = new Process("{$this->wp} core install --url={$url} --title={$blogTitle} --admin_user={$username} --admin_password={$password} --admin_email={$email}", $this->input->getArgument('name'));
        $process->run();

        if(!$process->isSuccessful()) {
            $this->output->writeln('<error>'.$process->getErrorOutput().'</error>');

            return $this->installWordpress();
        }

        return $this;
    }
}
