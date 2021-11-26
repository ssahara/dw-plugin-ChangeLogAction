<?php

use dokuwiki\Changelog\PageChangeLog;

/**
 * DokuWiki Action component of ChangeLoggingAction Plugin
 *
 * @license    GPL 2 (https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
 * @author  Sahara Satoshi <sahara.satoshi@gmail.com>
 */
class action_plugin_changeloggingaction extends DokuWiki_Action_Plugin
{
    protected $pagelog;

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
        $id = $data['id'];
        $pagelog = new PageChangeLog($id);
        $revInfo = $pagelog->getCurrentRevisionInfo();

        if (!$this->getConf('enable_action')) return;

        // only interested in external revision
        if (empty($revInfo) || !array_key_exists('timestamp', $revInfo)) return;

        /*
            $data = array(
                'id'             => $id,       // should not be altered by any handlers
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
            );
        */

        $lastRev = $pagelog->lastRevision();
        $lastRevInfo = $lastRev ? $pagelog->getRevisionInfo($lastRev) : false;

        $data['oldRevision'] = $lastRev;
        $data['oldContent']  = $this->rawWikiText($pagelog, $id, $lastRev);
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
     * Get raw WikiText of the page
     *
     * @param PageChangeLog $pagelog
     * @param string $id      page id
     * @param int|false $rev  timestamp when a revision of wikitext is desired
     * @return string
     */
    protected function rawWikiText(PageChangeLog $pagelog, $id, $rev = null)
    {
        if ($rev !== null) {
            $revInfo = $rev ? $pagelog->getRevisionInfo($rev) : false;
            return (!$revInfo || $revInfo['type'] == DOKU_CHANGE_TYPE_DELETE)
                ? ''
                : rawWiki($id, $rev); // retrieve from attic
        } else {
            return rawWiki($id);
        }
    }

    /**
     * Revert the page file to the past
     *
     * @param string $filename
     * @param string $content
     * @param int $timestamp
     */
    protected function restorePastFile($filename, $content, $timestamp = null)
    {
        if ($content === '') {
            unlink($filename);
        } else {
            file_put_contents($filename, $content);
            touch($filename, $timestamp);
            clearstatcache();
        }
    }

/* --------------------------------------------------------------------- *

        if ($data['revertFrom']) {
            // 復元するつもり
            if (!$lastRevInfo || $lastRevInfo['type'] == DOKU_CHANGE_TYPE_DELETE) {
                $data['oldRevision'] = $lastRev;
                $data['oldContent']  = ''; //$this->rawWiki($pagelog, $id, $lastRev);
                $data['sizechange']  = strlen($data['newContent']);
                unlink($data['file']);
            } else {
                $data['oldRevision'] = $lastRev;
                $data['oldContent']  = $this->rawWiki($pagelog, $id, $lastRev);
                $data['sizechange']  = strlen($data['newContent']) - strlen($data['oldContent']);
                file_put_contents($data['file'], $data['oldContent']);
                touch($data['file'], $data['oldRevision']);
                clearstatcache();
            }
        } elseif (trim($data['newContent']) == '') {
            // カレントを削除するつもり
            $data['oldRevision'] = $lastRev;
            $data['oldContent']  = $this->rawWiki($pagelog, $id, $lastRev);
            if (!$lastRevInfo || $lastRevInfo['type'] == DOKU_CHANGE_TYPE_DELETE) {
                // ラストは削除されている状態のはず…外部作成または編集されたファイルを削除するだけでよい
                unlink($data['file']);
                $data['contentChanged'] = false;
            } else {
                file_put_contents($data['file'], $data['oldContent']);
                touch($data['file'], $data['oldRevision']);
                clearstatcache();
            }
        } elseif ($data['changeType'] == DOKU_CHANGE_TYPE_CREATE) {
            // 新規作成するつもり、つまり current file は存在しない
            if (!$lastRevInfo || $lastRevInfo['type'] == DOKU_CHANGE_TYPE_DELETE) {
                // 何もすることはない
            } else {
                // ラストはファイルが散在していたはず…新規作成ではなく編集になる
                $data['changeType'] = DOKU_CHANGE_TYPE_EDIT;
                $data['oldRevision'] = $lastRev;
                $data['oldContent']  = $this->rawWiki($pagelog, $id, $lastRev);
                $data['sizechange']  = strlen($data['newContent']) - strlen($data['oldContent']);
                file_put_contents($data['file'], $data['oldContent']);
                touch($data['file'], $data['oldRevision']);
                clearstatcache();
            }
        } else {
            // 既存ページを編集するつもり、つまりファイルが存在している
            if (!$lastRevInfo || $lastRevInfo['type'] == DOKU_CHANGE_TYPE_DELETE) {
                // ラストはファイルが散在していなかったはず…編集ではなく新規作成になる
                $data['changeType'] = DOKU_CHANGE_TYPE_CREATE;
                $data['oldRevision'] = $lastRev;
                $data['oldContent']  = '';
                $data['sizechange']  = strlen($data['newContent']);
                unlink($data['file']);
            } else {
                $data['oldRevision'] = $lastRev;
                $data['oldContent']  = $this->rawWiki($pagelog, $id, $lastRev);
                $data['sizechange']  = strlen($data['newContent']) - strlen($data['oldContent']);
                file_put_contents($data['file'], $data['oldContent']);
                touch($data['file'], $data['oldRevision']);
                clearstatcache();
            }
        }

 * --------------------------------------------------------------------- */

}
