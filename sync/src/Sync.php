<?php

/**
 * Master class for handling all email sync activities.
 */

namespace App;

use PhpImap\Mailbox
  , Monolog\Logger
  , Pimple\Container
  , League\CLImate\CLImate
  , App\Models\Folder as FolderModel
  , App\Models\Account as AccountModel
  , App\Exceptions\FolderSync as FolderSyncException
  , App\Exceptions\MissingIMAPConfig as MissingIMAPConfigException
  , App\Exceptions\AttachmentsPathNotWriteable as AttachmentsPathNotWriteableException;

class Sync
{
    private $cli;
    private $log;
    private $config;
    private $mailbox;
    private $retries;
    private $interactive;
    private $maxRetries = 5;
    private $retriesFolders;
    private $retriesMessages;

    /**
     * Constructor can optionally take a dependency container or
     * have the dependencies loaded individually. The di method is
     * used when the sync app is run from a bootstrap file and the
     * ad hoc method is when this class is used separately within
     * other classes like Console.
     * @param array $di Service container
     */
    function __construct( Container $di = NULL )
    {
        $this->retries = [];
        $this->retriesFolders = [];
        $this->retriesMessages = [];

        if ( $di ) {
            $this->cli = $di[ 'cli' ];
            $this->log = $di[ 'log' ];
            $this->config = $di[ 'config' ];
            $this->interactive = $di[ 'console' ]->interactive;
        }
    }

    /**
     * @param CLImate $cli
     */
    function setCLI( CLImate $cli )
    {
        $this->cli = $cli;
    }

    /**
     * @param Logger $log
     */
    function setLog( Logger $log )
    {
        $this->log = $log;
    }

    /**
     * @param array $config
     */
    function setConfig( array $config )
    {
        $this->config = $config;
    }

    /**
     * For each account:
     *  1. Get the folders
     *  2. Save all message IDs for each folder
     *  3. For each folder, add/remove messages based off IDs
     *  4. Set up threading for messages based off UIDs
     * @param AccountModel $account Optional account to run
     */
    function run( AccountModel $account = NULL )
    {
        if ( $account ) {
            $accounts = [ $account ];
        }
        else {
            $accountModel = new AccountModel;
            $accounts = $accountModel->getActive();
        }

        // Loop through the active accounts and perform the sync
        // sequentially. The IMAP methods throw exceptions so want
        // to wrap this is a try/catch block.
        foreach ( $accounts as $account ) {
            $this->retries[ $account->email ] = 1;
            $this->runAccount( $account );
        }
    }

    /**
     * Runs the sync script for an account. Each action (i.e. connecting
     * to the server, syncing folders, syncing messages, etc) should be
     * allowed to fail a certain number of times before the account is
     * considered offline.
     * @param AccountModel $account
     * @return boolean
     */
    private function runAccount( AccountModel $account )
    {
        if ( $this->retries[ $account->email ] > $this->maxRetries ) {
            $this->log->notice(
                "The account '{$account->email}' has exceeded the max ".
                "amount of retries after failure ({$this->maxRetries}) ".
                "and is no longer being attempted to sync again." );
            return FALSE;
        }

        $this->log->info( "Starting sync for {$account->email}" );

        try {
            // Open a connection to the mailbox
            $this->connect(
                $account->service,
                $account->email,
                $account->password );
            // Fetch folders and sync them to database
            $this->retriesFolders[ $account->email ] = 1;
            $folders = $this->syncFolders( $account );
            // Process the messages in each folder
            foreach ( $folders as $folder ) {
                $this->retriesMessages[ $account->email ] = 1;
                $this->syncMessages( $account, $folder );
            }
        }
        catch ( \Exception $e ) {
            $this->log->error( $e->getMessage() );
            $waitSeconds = $this->config[ 'app' ][ 'sync' ][ 'wait_seconds' ];
            $this->log->info(
                "Re-trying sync ({$this->retries[ $account->email ]}/".
                "{$this->maxRetries}) in $waitSeconds seconds..." );
            sleep( $waitSeconds );
            $this->retries[ $account->email ]++;

            return $this->runAccount( $account );
        }

        $this->log->info( "Sync complete for {$account->email}" );

        return TRUE;
    }

    /**
     * Connects to an IMAP mailbox using the supplied credentials.
     * @param string $type Account type, like "GMail"
     * @param string $email
     * @param string $password
     * @param string $folder Optional, like "INBOX"
     * @throws MissingIMAPConfigException
     */
    function connect( $type, $email, $password, $folder = "" )
    {
        $type = strtolower( $type );

        if ( ! isset( $this->config[ 'email' ][ $type ] ) ) {
            throw new MissingIMAPConfigException( $type );
        }

        // Check the attachment directory is writeable
        $attachmentsPath = $this->checkAttachmentsPath();
        $imapPath = $this->config[ 'email' ][ $type ][ 'path' ];

        $this->mailbox = new Mailbox(
            "{". $imapPath ."}". $folder,
            $email,
            $password,
            $attachmentsPath );
        $this->mailbox->checkMailbox();
    }

    /**
     * Checks if the attachments path is writeable by the user.
     * @throws AttachmentsPathNotWriteableException
     * @return boolean
     */
    private function checkAttachmentsPath()
    {
        $configPath = $this->config[ 'email' ][ 'attachments' ][ 'path' ];
        $attachmentsPath = ( substr( $configPath, 0, 1 ) !== "/" )
            ? __DIR__
            : $configPath;

        if ( ! is_writeable( $attachmentsPath ) ) {
            throw new AttachmentsPathNotWriteableException;
        }

        return $attachmentsPath;
    }

    /**
     * Syncs a collection of IMAP folders to the database.
     * @param AccountModel $account Account to sync
     * @throws FolderSyncException
     * @return array $folders List of IMAP folders
     */
    private function syncFolders( AccountModel $account )
    {
        if ( $this->retriesFolders[ $account->email ] > $this->maxRetries ) {
            $this->log->notice(
                "The account '{$account->email}' has exceeded the max ".
                "amount of retries after folder sync failure ".
                "({$this->maxRetries})." );
            throw new FolderSyncException;
        }

        $i = 1;
        $progress = NULL;
        $this->log->debug( "Syncing IMAP folders for {$account->email}" );

        try {
            $folders = $this->mailbox->getListingFolders();
            $count = count( $folders );

            if ( $this->interactive ) {
                $this->cli->whisper( "Syncing $count folders:" );
                $progress = $this->cli->progress()->total( 100 );
            }

            // Add each folder to SQL, mark old folders as deleted
            foreach ( $folders as $folder ) {
                if ( $this->interactive ) {
                    FolderModel::create([
                        'name' => $folder,
                        'account_id' => $account->id
                    ]);
                    $progress->current( ( $i++ / $count ) * 100 );
                }
            }
        }
        catch ( \Exception $e ) {
            $this->log->error( $e->getMessage() );
            $waitSeconds = $this->config[ 'app' ][ 'sync' ][ 'wait_seconds' ];
            $this->log->info(
                "Re-trying folder sync ({$this->retriesFolders[ $account->email ]}/".
                "{$this->maxRetries}) in $waitSeconds seconds..." );
            sleep( $waitSeconds );
            $this->retriesFolders[ $account->email ]++;

            return $this->syncFolders( $account );
        }

        return $folders;
    }

    /**
     * Syncs all of the messages for a given IMAP folder.
     * @param AccountModel $account
     * @param string $folder
     * @throws MessageSyncException
     * @return bool
     */
    private function syncMessages( AccountModel $account, $folder )
    {
        if ( $this->retriesMessages[ $account->email ] > $this->maxRetries ) {
            $this->log->notice(
                "The account '{$account->email}' has exceeded the max ".
                "amount of retries after message sync failure ".
                "({$this->maxRetries})." );
            throw new MessageSyncException( $folder );
        }

        $i = 1;
        $progress = NULL;
        $this->log->debug(
            "Syncing messages in $folder for {$account->email}" );

        // Syncing a folder of messages is done using the following
        // algorithm:
        //  1. Get all message IDs
        //  2. Get all message IDs saved in SQL
        //  3. For anything in 1 and not 2, download messages and save
        //     to SQL database
        //  4. Mark deleted in SQL anything in 2 and not 1
        //  5. Update relationship table with links between messages
        //     and this folder.
        try {

        }
        catch ( \Exception $e ) {
            $this->log->error( $e->getMessage() );
            $waitSeconds = $this->config[ 'app' ][ 'sync' ][ 'wait_seconds' ];
            $this->log->info(
                "Re-trying message sync ({$this->retriesMessages[ $account->email ]}/".
                "{$this->maxRetries}) in $waitSeconds seconds..." );
            sleep( $waitSeconds );
            $this->retriesMessages[ $account->email ]++;

            return $this->syncMessages( $account, $folder );
        }
    }
}