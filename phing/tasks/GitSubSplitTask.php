<?php
/**
 * Phing wrapper around git subsplit.
 * 
 *
 * @see https://github.com/dflydev/git-subsplit
 * @copyright 2012 Clay Loveless <clay@php.net>
 * @license   http://claylo.mit-license.org/2012/ MIT License
 */

require_once 'phing/tasks/ext/git/GitBaseTask.php';

// base - base of tree to split out
// subIndicatorFile - composer.json, package.xml?

class GitSubSplitTask extends GitBaseTask
{   
    /**
     * What git repository to pull from and publish to
     */
    protected $remote           = null;
    
    /**
     * Publish for comma-separated heads instead of all heads
     */
    protected $heads            = null;
    
    /**
     * Base of the tree RELATIVE TO .subsplit working dir
     */
    protected $base             = null;
    
    /**
     * The presence of this file will indicate that the directory it resides
     * in is at the top level of a split.
     */
    protected $subIndicatorFile = 'composer.json';

    /**
     * Do everything except actually send the update.
     */
    protected $dryRun           = null;
    
    public function setRemote($str)
    {
        $this->remote = $str;
    }
    
    public function getRemote()
    {
        return $this->remote;
    }
    
    public function setHeads($str)
    {
        $this->heads = explode(',', $str);
    }
    
    public function getHeads()
    {
        return $this->heads;
    }
    
    public function setBase($str)
    {
        $this->base = $str;
    }
    
    public function getBase()
    {
        return $this->base;
    }
    
    public function setSubIndicatorFile($str)
    {
        $this->subIndicatorFile = $str;
    }
    
    public function getSubIndicatorFile()
    {
        return $this->subIndicatorFile;
    }
    
    public function setDryRun($bool)
    {
        $this->dryRun = (bool) $bool;
    }
    
    public function getDryRun()
    {
        return $this->dryRun;
    }
    /**
     * GitClient from VersionControl_Git
     */
    protected $client   = null;
    
    /**
     * The main entry point
     */
    public function main()
    {
        $repo = $this->getRepository();
        if (empty($repo)) {
            throw new BuildException('"repository" is a required parameter');
        }

        $remote = $this->getRemote();
        if (empty($remote)) {
            throw new BuildException('"remote" is a required parameter');
        }
        
        chdir($repo);
        $this->client = $this->getGitClient(false, $repo);
        
        // initalized yet?
        if (!is_dir('.subsplit')) {
            $this->subsplitInit();
        } else {
            // update
            $this->subsplitUpdate();
        }
        
        // crawl the base tree
        $splits = $this->findSplits();
    }
    
    /**
     * Runs `git subsplit update`
     */
    public function subsplitUpdate()
    {
        $this->log('git-subsplit update...');
        $cmd = $this->client->getCommand('subsplit');
        $cmd->addArgument('update');
        try {
            $output = $cmd->execute();
        } catch (Exception $e) {
            throw new BuildException('git subsplit update failed'. $e);
        }        
    }
    
    /**
     * Runs `git subsplit init` based on the remote repository.
     */
    public function subsplitInit()
    {
        $remote = $this->getRemote();
        $cmd = $this->client->getCommand('subsplit');
        $this->log('running git-subsplit init ' . $remote);
        
        $cmd->setArguments(array(
            'init',
            $remote
        ));
        
        try {
            $output = $cmd->execute();
        } catch (Exception $e) {
            throw new BuildException('git subsplit init failed'. $e);
        }
        $this->log(trim($output), Project::MSG_INFO);        
    }
    
    /**
     * Find the composer.json files using Phing's directory scanner
     * 
     * @return array
     */
    protected function findSplits()
    {
        $repo = $this->getRepository();
        $base = $this->getBase();
        if (!empty($base)) {
            $base = '/' . ltrim($base, '/');
        } else {
            $base = '/';
        } 
        
        $ds = new DirectoryScanner();
        $ds->setBasedir($repo . '/.subsplit' . $base);
        $ds->setIncludes(array('**/'.$this->subIndicatorFile));
        $ds->scan();
        $files = $ds->getIncludedFiles();
        print_r($files);
    }
    
}