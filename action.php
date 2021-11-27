<?php

/**
 * DokuWiki Action component of ChangeLoggingAction Plugin
 *
 * @license    GPL 2 (https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
 * @author  Sahara Satoshi <sahara.satoshi@gmail.com>
 */
class action_plugin_changeloggingaction extends DokuWiki_Action_Plugin
{
    // register hook
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('COMMON_WIKIPAGE_SAVE', 'BEFORE', $this, 'restoreLastRevision');
    }

    /**
     * Restore the page souce file of last revision to pages from attic
     */
    public function restoreLastRevision(Doku_Event $event)
    {
        $data =& $event->data;
        /*
            PageFile::$data = array(
                'id'             => $this->id, // should not be altered by any handlers
                'file'           => $pagefile, // same above
                'changeType'     => null,      // set prior to event, and confirm later
                'revertFrom'     => $REV,
                'oldRevision'    => $currentRevision,
                'oldContent'     => $currentContent,
                'newRevision'    => 0,         // only available in the after hook
                'newContent'     => $text,
                'summary'        => $summary,
                'contentChanged' => (bool)($text != $currentContent), // confirm later
                'changeInfo'     => '',        // automatically determined by revertFrom
                'sizechange'     => strlen($text) - strlen($currentContent), // TBD
                'page'           => $this,     // allow handlers to use class methods
            );
        */

        /* @var dokuwiki\File\PageFile $page */
        $page = $data['page'];

        if (!$this->getConf('enable_action')) return;

        // only interested in external revision
        $revInfo = $page->changelog->getCurrentRevisionInfo();
        if (empty($revInfo) || !array_key_exists('timestamp', $revInfo)) return;

        $lastRev = $page->changelog->lastRevision();
        $lastRevInfo = $lastRev ? $page->changelog->getRevisionInfo($lastRev) : false;

        $data['oldRevision'] = $lastRev;
        $data['oldContent']  = $page->rawWikiText($lastRev);
        $data['sizechange']  = strlen($data['newContent']) - strlen($data['oldContent']);

        // revert the file at last change, and pretend to ignore external edit
        $this->restorePastFile($data['file'], $data['oldContent'], $data['oldRevision']);

        if ($data['revertFrom']) {
            // intended to revert an old revision, nothing to do

        } elseif (trim($data['newContent']) == '') {
            // intended to delete the current page file which was externally modified
            if ($data['oldContent'] == '') {
                // file did not existed at last change time, already deleted
                $data['contentChanged'] = false;
            }
        } elseif ($data['changeType'] == DOKU_CHANGE_TYPE_CREATE) {
            // intended to create a new page, this means no page file exists
            if ($data['oldContent'] != '') {
                // file existed at last change time, therefore change type is edit instead of create
                $data['changeType'] = DOKU_CHANGE_TYPE_EDIT;
            }
        } else {
            // intended to edite existing page
            if ($data['oldContent'] == '') {
                // file did not existed at last change time, therefore change type is create instead of edit
                $data['changeType'] = DOKU_CHANGE_TYPE_CREATE;
            }
        }
    }

    /**
     * Revert the page file to the past
     *
     * @param string $filepath
     * @param string $content
     * @param int $timestamp
     */
    protected function restorePastFile($filepath, $content, $timestamp = null)
    {
        if ($content === '') {
            unlink($filepath);
        } else {
            file_put_contents($filepath, $content);
            touch($filepath, $timestamp);
            clearstatcache();
        }
    }

}
