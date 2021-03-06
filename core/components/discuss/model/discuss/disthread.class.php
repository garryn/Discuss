<?php
/**
 * Discuss
 *
 * Copyright 2010-11 by Shaun McCormick <shaun@modx.com>
 *
 * This file is part of Discuss, a native forum for MODx Revolution.
 *
 * Discuss is free software; you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * Discuss is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR
 * A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * Discuss; if not, write to the Free Software Foundation, Inc., 59 Temple Place,
 * Suite 330, Boston, MA 02111-1307 USA
 *
 * @package discuss
 */
/**
 * The base class for Threads, which are compilations of Posts in a Board.
 *
 * This class may be extended to provide derivative functionality.
 *
 * @property string $class_key
 * @property int $board
 * @property string $title
 * @property int $post_first
 * @property int $post_last
 * @property int $author_first
 * @property int $author_last
 * @property int $replies
 * @property int $views
 * @property boolean $locked
 * @property boolean $answered
 * @property boolean $sticky
 * @property boolean $private
 * @property string $users
 * @property string $last_view_ip
 * @property int $integrated_id
 *
 * @property disUser $FirstAuthor
 * @property disUser $LastAuthor
 * @property disPost $FirstPost
 * @property disPost $LastPost
 * @property disBoard $Board
 * @property array $Reads
 * @property array $Posts
 * @property array $Notifications
 * @property array $Users
 *
 * @see disPost
 * @package discuss
 */
class disThread extends xPDOSimpleObject {
    /**
     * The type-key for a Post-type thread
     * @const TYPE_POST
     */
    const TYPE_POST = 'post';
    /**
     * The type key for a Message-type thread
     * @const TYPE_MESSAGE
     */
    const TYPE_MESSAGE = 'message';

    /**
     * Whether or not the active user has a subscription to this thread.
     * @see disThread::hasSubscription
     * @var boolean $hasSubscription
     */
    public $hasSubscription;
    
    /**
     * Fetch a thread, but check permissions first
     * 
     * @static
     * @param xPDO $modx A reference to the modX instance
     * @param $id The ID of the thread
     * @param string $type The type of thread: post/message
     * @param boolean $integrated Grab by the integrated_id instead of the id
     * @return disThread/null The returned, grabbed thread; or false/null if not found/accessible
     */
    public static function fetch(xPDO &$modx, $id, $type = disThread::TYPE_POST, $integrated = false) {
        $c = $modx->newQuery('disThread');
        $c->innerJoin('disPost','FirstPost');
        $c->select($modx->getSelectColumns('disThread','disThread'));
        $c->select(array(
            'FirstPost.title',
            '(SELECT GROUP_CONCAT(pAuthor.id)
                FROM '.$modx->getTableName('disPost').' AS pPost
                INNER JOIN '.$modx->getTableName('disUser').' AS pAuthor ON pAuthor.id = pPost.author
                WHERE pPost.thread = disThread.id
             ) AS participants',
        ));
        if ($integrated) {
            $c->where(array(
                'disThread.integrated_id' => trim($id,'.'),
            ));
        } else {
            $c->where(array(
                'disThread.id' => $id,
            ));
        }
        if ($type == disThread::TYPE_POST) {
            $c->innerJoin('disBoard','Board');
            $c->where(array(
                'Board.status:!=' => disBoard::STATUS_INACTIVE,
            ));
            $c->leftJoin('disBoardUserGroup','UserGroups','Board.id = UserGroups.board');
            $groups = $modx->discuss->user->getUserGroups();
            if (!$modx->discuss->user->isAdmin()) {
                if (!empty($groups)) {
                    /* restrict boards by user group if applicable */
                    $g = array(
                        'UserGroups.usergroup:IN' => $groups,
                    );
                    $g['OR:UserGroups.usergroup:IS'] = null;
                    $where[] = $g;
                    $c->andCondition($where,null,2);
                } else {
                    $c->where(array(
                        'UserGroups.usergroup:IS' => null,
                    ));
                }
            }
        }
        if ($type == disThread::TYPE_MESSAGE) {
            $c->innerJoin('disThreadUser','Users');
            $c->leftJoin('disUser','ThreadUser','Users.user = ThreadUser.id');
            $c->where(array(
                'Users.user' => $modx->discuss->user->get('id'),
            ));
            $c->select(array(
                '(SELECT GROUP_CONCAT(sqThreadUser.username)
                    FROM '.$modx->getTableName('disThreadUser').' AS sqThreadUsers
                        INNER JOIN '.$modx->getTableName('disUser').' AS sqThreadUser
                        ON sqThreadUser.id = sqThreadUsers.user
                    WHERE sqThreadUsers.thread = disThread.id
                 ) AS participants_usernames',
            ));
        }
        $thread = $modx->getObject('disThread',$c);
        if ($thread && $type == disThread::TYPE_MESSAGE) {
            $pu = array_unique(explode(',',$thread->get('participants_usernames')));
            asort($pu);
            $thread->set('participants_usernames',implode(',',$pu));
        }
        return $thread;
    }

    /**
     * Fetch all unread, accessible threads
     * 
     * @static
     * @param xPDO $modx A reference to the modX instance
     * @param string $sortBy The column to sort by
     * @param string $sortDir The direction to sort
     * @param int $limit The # of threads to limit
     * @param int $start The index to start by
     * @param boolean $sinceLastLogin
     * @return array An array in results/total format
     */
    public static function fetchUnread(xPDO &$modx,$sortBy = 'LastPost.createdon',$sortDir = 'DESC',$limit = 20,$start = 0,$sinceLastLogin = false) {
        $response = array();
        $c = $modx->newQuery('disThread');
        $c->innerJoin('disBoard','Board');
        $c->innerJoin('disPost','FirstPost');
        $c->innerJoin('disPost','LastPost');
        $c->innerJoin('disThread','LastPostThread','LastPostThread.id = LastPost.thread');
        $c->innerJoin('disUser','LastAuthor');
        $c->leftJoin('disThreadRead','Reads','Reads.thread = disThread.id AND Reads.user = '.$modx->discuss->user->get('id'));
        $c->leftJoin('disBoardUserGroup','UserGroups','Board.id = UserGroups.board');
        $groups = $modx->discuss->user->getUserGroups();
        if (!$modx->discuss->user->isAdmin()) {
            if (!empty($groups)) {
                /* restrict boards by user group if applicable */
                $g = array(
                    'UserGroups.usergroup:IN' => $groups,
                );
                $g['OR:UserGroups.usergroup:IS'] = null;
                $where[] = $g;
                $c->andCondition($where,null,2);
            } else {
                $c->where(array(
                    'UserGroups.usergroup:IS' => null,
                ));
            }
        }
        $c->where(array(
            'Reads.thread:IS' => null,
            'Board.status:!=' => disBoard::STATUS_INACTIVE,
        ));

        /* ignore spam/recycle bin boards */
        $spamBoard = $modx->getOption('discuss.spam_bucket_board',null,false);
        if (!empty($spamBoard)) {
            $c->where(array(
                'Board.id:!=' => $spamBoard,
            ));
        }
        $trashBoard = $modx->getOption('discuss.recycle_bin_board',null,false);
        if (!empty($trashBoard)) {
            $c->where(array(
                'Board.id:!=' => $trashBoard,
            ));
        }

        /* usergroup protection */
        if ($modx->discuss->isLoggedIn) {
            if ($sinceLastLogin) {
                $lastLogin = $modx->discuss->user->get('last_login');
                if (!empty($lastLogin)) {
                    $c->where(array(
                        'LastPost.createdon:>=' => $lastLogin,
                    ));
                }
            }
            $ignoreBoards = $modx->discuss->user->get('ignore_boards');
            if (!empty($ignoreBoards)) {
                $c->where(array(
                    'Board.id:NOT IN' => explode(',',$ignoreBoards),
                ));
            }
        }
        $response['total'] = $modx->getCount('disThread',$c);
        $c->select($modx->getSelectColumns('disThread','disThread'));
        $c->select(array(
            'Board.name AS board_name',
            'FirstPost.title AS title',
            'FirstPost.thread AS thread',
            'LastAuthor.username AS author_username',

            'LastPost.id AS post_id',
            'LastPost.createdon AS createdon',
            'LastPost.author AS author',
            'LastPostThread.replies AS last_post_replies',
        ));
        $c->sortby($sortBy,$sortDir);
        $c->limit($limit,$start);
        $response['results'] = $modx->getCollection('disThread',$c);

        return $response;
    }


    /**
     * Fetch all new replies in threads that the active user is a participant in
     *
     * @static
     * @param xPDO $modx A reference to the modX instance
     * @param string $sortBy The column to sort by
     * @param string $sortDir The direction to sort
     * @param int $limit The # of threads to limit
     * @param int $start The index to start by
     * @param boolean $sinceLastLogin
     * @return array An array in results/total format
     */
    public static function fetchNewReplies(xPDO &$modx,$sortBy = 'LastPost.createdon',$sortDir = 'DESC',$limit = 20,$start = 0,$sinceLastLogin = false) {
        $response = array();
        $c = $modx->newQuery('disThread');
        $c->innerJoin('disBoard','Board');
        $c->innerJoin('disPost','FirstPost');
        $c->innerJoin('disPost','LastPost');
        $c->innerJoin('disThread','LastPostThread','LastPostThread.id = LastPost.thread');
        $c->innerJoin('disUser','LastAuthor');
        $c->leftJoin('disThreadRead','Reads','Reads.thread = disThread.id AND Reads.user = '.$modx->discuss->user->get('id'));
        $c->leftJoin('disBoardUserGroup','UserGroups','Board.id = UserGroups.board');
        $groups = $modx->discuss->user->getUserGroups();
        if (!$modx->discuss->user->isAdmin()) {
            if (!empty($groups)) {
                /* restrict boards by user group if applicable */
                $g = array(
                    'UserGroups.usergroup:IN' => $groups,
                );
                $g['OR:UserGroups.usergroup:IS'] = null;
                $where[] = $g;
                $c->andCondition($where,null,2);
            } else {
                $c->where(array(
                    'UserGroups.usergroup:IS' => null,
                ));
            }
        }
        $c->where(array(
            'Reads.thread:IS' => null,
            'Board.status:!=' => disBoard::STATUS_INACTIVE,
            $modx->discuss->user->get('id').' IN(
             (
                SELECT GROUP_CONCAT(pAuthor.id)
                FROM '.$modx->getTableName('disPost').' AS pPost
                INNER JOIN '.$modx->getTableName('disUser').' AS pAuthor ON pAuthor.id = pPost.author
                WHERE pPost.thread = disThread.id
             )
            )',
        ));

        /* ignore spam/recycle bin boards */
        $spamBoard = $modx->getOption('discuss.spam_bucket_board',null,false);
        if (!empty($spamBoard)) {
            $c->where(array(
                'Board.id:!=' => $spamBoard,
            ));
        }
        $trashBoard = $modx->getOption('discuss.recycle_bin_board',null,false);
        if (!empty($trashBoard)) {
            $c->where(array(
                'Board.id:!=' => $trashBoard,
            ));
        }

        /* usergroup protection */
        if ($modx->discuss->isLoggedIn) {
            if ($sinceLastLogin) {
                $lastLogin = $modx->discuss->user->get('last_login');
                if (!empty($lastLogin)) {
                    $c->where(array(
                        'LastPost.createdon:>=' => $lastLogin,
                    ));
                }
            }
            $ignoreBoards = $modx->discuss->user->get('ignore_boards');
            if (!empty($ignoreBoards)) {
                $c->where(array(
                    'Board.id:NOT IN' => explode(',',$ignoreBoards),
                ));
            }
        }
        $response['total'] = $modx->getCount('disThread',$c);
        $c->select($modx->getSelectColumns('disThread','disThread'));
        $c->select(array(
            'Board.name AS board_name',
            'FirstPost.title AS title',
            'FirstPost.thread AS thread',
            'LastAuthor.username AS author_username',

            'LastPost.id AS post_id',
            'LastPost.createdon AS createdon',
            'LastPost.author AS author',
            'LastPostThread.replies AS last_post_replies',
        ));
        $c->sortby($sortBy,$sortDir);
        $c->limit($limit,$start);
        $response['results'] = $modx->getCollection('disThread',$c);

        return $response;
    }

    /**
     * Mark all posts in this thread as read
     * @static
     * @param xPDO $modx
     * @param string $type
     * @return bool
     */
    public static function readAll(xPDO &$modx,$type = 'message') {
        $userId = $modx->discuss->user->get('id');
        $sql = 'SELECT `disThread`.`id`
        FROM '.$modx->getTableName('disThread').' `disThread`
            INNER JOIN '.$modx->getTableName('disThreadUser').' `ThreadUser`
            ON `ThreadUser`.`thread` = `disThread`.`id`
            LEFT JOIN '.$modx->getTableName('disThreadRead').' `ThreadRead`
            ON `ThreadRead`.`thread` = `disThread`.`id`
        WHERE
            `ThreadUser`.`user` = '.$userId.'
        AND `ThreadRead`.`id` IS NULL
        AND `private` = 1
        ORDER BY `disThread`.`id` DESC';
        $stmt = $modx->query($sql);
        if (!$stmt) return false;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $read = $modx->getCount('disThreadRead',array(
                'thread' => $row['id'],
                'user' => $userId,
            ));
            if ($read == 0) {
                $read = $modx->newObject('disThreadRead');
                $read->fromArray(array(
                    'thread' => $row['id'],
                    'board' => 0,
                    'user' => $userId,
                ));
                $read->save();
            }
        }
        $stmt->closeCursor();
        return true;
    }

    /**
     * Override remove() to clear thread cache
     *
     * @param array $ancestors
     * @param boolean $doBoardMoveChecks
     * @param boolean $moveToSpam
     * @return boolean
     */
    public function remove(array $ancestors = array(),$doBoardMoveChecks = false,$moveToSpam = false) {
        $removed = false;

        if (!empty($doBoardMoveChecks)) {
            $board = $this->getOne('Board');
            if (!empty($board) && !$this->get('private')) {
                $isModerator = $board->isModerator($this->xpdo->discuss->user->get('id'));
                $isAdmin = $this->xpdo->discuss->user->isAdmin();
                if ($isModerator || $isAdmin) { /* move to spambox/recyclebin */
                    $spamBoard = $this->xpdo->getOption('discuss.spam_bucket_board',null,false);
                    if ($moveToSpam && !empty($spamBoard) && $spamBoard != $this->get('board')) {
                        if ($this->move($spamBoard)) {
                            $removed = true;
                        }
                    } else {
                        $trashBoard = $this->xpdo->getOption('discuss.recycle_bin_board',null,false);
                        if (!empty($trashBoard) && $trashBoard != $this->get('board')) {
                            if ($this->move($trashBoard)) {
                                $removed = true;
                            }
                        }
                    }
                }
            }
        }

        if (!$removed) {
            $removed = parent::remove($ancestors);
        }

        if ($removed) {
            $this->clearCache();
            /* clear recent posts cache */
            $this->xpdo->cacheManager->delete('discuss/board/recent/');
        }
        return $removed;
    }

    /**
     * Save the Thread
     *
     * @param null $cacheFlag
     * @param bool $clearCache
     * @return boolean
     */
    public function save($cacheFlag = null,$clearCache = true) {
        $saved = parent::save($cacheFlag);
        if ($saved && $clearCache) {
            $this->clearCache();
        }
        return $saved;
    }

    /**
     * Always ensure that the title strips any HTML/MODX tags
     * @param string $k
     * @param string|null $format
     * @param string|null $formatTemplate
     * @return mixed
     */
    public function get($k, $format = null, $formatTemplate= null) {
        $v = parent::get($k,$format,$formatTemplate);
        switch ($k) {
            case 'title':
                $v = $this->xpdo->discuss->stripAllTags($v);
                break;
            default: break;
        }
        return $v;
    }

    /**
     * @param string $keyPrefix
     * @param bool $rawValues
     * @param bool $excludeLazy
     * @return array
     */
    public function toArray($keyPrefix= '', $rawValues= false, $excludeLazy= false) {
        $array = parent::toArray($keyPrefix,$rawValues,$excludeLazy);
        foreach ($array as $k => &$v) {
            if ($k == 'title') {
                $v = $this->xpdo->discuss->stripAllTags($v);
            }
        }
        reset($array);
        return $array;
    }

    /**
     * Clear cache for this thread
     *
     * @return void
     */
    public function clearCache() {
        if (!defined('DISCUSS_IMPORT_MODE')) {
            $this->xpdo->getCacheManager();
            $this->xpdo->cacheManager->delete('discuss/thread/'.$this->get('id'));
            $this->xpdo->cacheManager->delete('discuss/board/'.$this->get('board'));
            $this->xpdo->cacheManager->delete('discuss/board/'.$this->get('board').'/');
            $this->xpdo->cacheManager->delete('discuss/board/user/');
            $this->xpdo->cacheManager->delete('discuss/board/index/');
        }
    }

    
    /**
     * Gets the viewing message for the bottom of the thread
     *
     * @access public
     * @param string $placePrefix
     * @return string The who is viewing message
     */
    public function getViewing($placePrefix = 'thread') {
        if (!$this->xpdo->getOption('discuss.show_whos_online',null,true)) return '';
        if (!$this->xpdo->hasPermission('discuss.view_online')) return '';
        $canViewProfiles = $this->xpdo->hasPermission('discuss.view_profiles');

        $c = $this->xpdo->newQuery('disSession');
        $c->innerJoin('disUser','User');
        $c->select($this->xpdo->getSelectColumns('disSession','disSession','',array('id')));
        $c->select(array(
            'CONCAT_WS(":",User.id,IF(User.use_display_name,User.display_name,User.username)) AS reader',
        ));
        $c->where(array(
            'disSession.place' => $placePrefix.':'.$this->get('id'),
        ));
        $c->groupby('disSession.user');
        $sessions = $this->xpdo->getCollection('disSession',$c);

        if (!empty($sessions)) {
            $members = array();
            foreach ($sessions as $member) {
                $r = explode(':',$member->get('reader'));
                $members[] = $canViewProfiles ? '<a href="'.$this->xpdo->discuss->request->makeUrl('user',array(
                    'user' => str_replace('%20','',$r[0])
                )).'">'.$r[1].'</a>' : $r[1];
            }
            $members = array_unique($members);
            $members = implode(',',$members);
        } else { $members = $this->xpdo->lexicon('discuss.zero_members'); }

        $c = $this->xpdo->newQuery('disSession');
        $c->where(array(
            'place' => $placePrefix.':'.$this->get('id'),
            'user' => 0,
        ));
        $guests = $this->xpdo->getCount('disSession',$c);

        return $this->xpdo->lexicon('discuss.thread_viewing',array(
            'members' => $members,
            'guests' => $guests,
        ));
    }

    /**
     * Marks a post read by the currently logged in user.
     *
     * @access public
     * @param int $user
     * @return boolean True if successful.
     */
    public function read($user) {
        $read = $this->xpdo->getObject('disThreadRead',array(
            'thread' => $this->get('id'),
            'user' => $user,
        ));
        if ($read != null) return false;

        $read = $this->xpdo->newObject('disThreadRead');
        $read->set('thread',$this->get('id'));
        $read->set('board',$this->get('board'));
        $read->set('user',$user);

        $saved = $read->save();
        if (!$saved) {
            $this->xpdo->log(modX::LOG_LEVEL_ERROR,'[Discuss] An error occurred while trying to mark read the post: '.print_r($read->toArray(),true));
        }
        return $saved;
    }

    /**
     * Marks a post unread by the currently logged in user.
     *
     * @access public
     * @param int $user
     * @return boolean True if successful.
     */
    public function unread($user) {
        $read = $this->xpdo->getObject('disThreadRead',array(
            'user' => $user,
            'thread' => $this->get('id'),
        ));
        if ($read == null) return true;

        $removed = $read->remove();
        if (!$removed) {
            $this->xpdo->log(modX::LOG_LEVEL_ERROR,'[Discuss] An error occurred while trying to mark unread the post: '.print_r($read->toArray(),true));
        }
        return $removed;
    }

    /**
     * Make thread sticky
     * 
     * @return boolean
     */
    public function stick() {
        $this->set('sticky',true);
        return $this->save();
    }

    /**
     * Make thread unsticky
     *
     * @return boolean
     */
    public function unstick() {
        $this->set('sticky',false);
        return $this->save();
    }

    /**
     * Lock thread
     *
     * @return boolean
     */
    public function lock() {
        $this->set('locked',true);
        return $this->save();
    }

    /**
     * Unlock thread
     *
     * @return boolean
     */
    public function unlock() {
        $this->set('locked',false);
        return $this->save();
    }

    /**
     * Check if a user (by id) has a subscription to this thread
     *
     * @param int $userId ID of disUser
     * @return bool True if has a subscription
     */
    public function hasSubscription($userId = 0) {
        if (!isset($this->hasSubscription)) {
            if (empty($userId)) $userId = $this->xpdo->discuss->user->get('id');

            $this->hasSubscription = $this->xpdo->getCount('disUserNotification',array(
                'user' => $userId,
                'thread' => $this->get('id'),
            )) > 0;
        }
        return $this->hasSubscription;
    }

    /**
     * Add a subscription to this thread for the User
     * 
     * @param int $userId ID of disUser
     * @return bool True if successful
     */
    public function addSubscription($userId) {
        $success = false;
        $notify = $this->xpdo->getObject('disUserNotification',array(
            'user' => $userId,
            'thread' => $this->get('id'),
        ));
        if (!$notify) {
            $notify = $this->xpdo->newObject('disUserNotification');
            $notify->set('user',$userId);
            $notify->set('thread',$this->get('id'));
            $notify->set('board',$this->get('board'));
            if (!$notify->save()) {
                $this->xpdo->log(xPDO::LOG_LEVEL_ERROR,'[Discuss] Could not create notification: '.print_r($notify->toArray(),true));
            } else {
                $success = true;
            }
        }
        return $success;
    }

    /**
     * Remove a subscription to this thread for the User
     *
     * @param int $userId ID of disUser
     * @return bool True if successful
     */
    public function removeSubscription($userId) {
        $success = false;
        $notify = $this->xpdo->getObject('disUserNotification',array(
            'user' => $userId,
            'thread' => $this->get('id'),
        ));
        if ($notify) {
            if (!$notify->remove()) {
                $this->xpdo->log(modX::LOG_LEVEL_ERROR,'[Discuss] Could not remove notification: '.print_r($notify->toArray(),true));
            } else {
                $success = true;
            }
        }
        return $success;
    }


    /**
     * Mark this thread unread for all users
     * @return bool True if successful
     */
    public function unreadForAll() {
        return $this->xpdo->exec('DELETE FROM '.$this->xpdo->getTableName('disThreadRead').' WHERE '.$this->xpdo->escape('thread').' = '.$this->get('id'));
    }

    /**
     * Get an array of disUser objects who are Moderators of this thread's board
     * @return array
     */
    public function getModerators() {
        $gms = $this->xpdo->getOption('discuss.global_moderators',null,'');
        $gms = explode(',',$gms);

        $c = $this->xpdo->newQuery('disUser');
        $c->leftJoin('disModerator','Moderator','Moderator.user = disUser.id AND Moderator.board = '.$this->get('board'));
        $c->where(array(
            'disUser.username:IN' => $gms,
            'OR:Moderator.id:!=' => null,
        ));
        return $this->xpdo->getCollection('disUser',$c);
    }

    /**
     * Build the breadcrumb trail for this thread
     *
     * @param array $defaultTrail
     * @return array
     */
    public function buildBreadcrumbs($defaultTrail = array()) {
        $c = $this->xpdo->newQuery('disBoard');
        $c->innerJoin('disBoardClosure','Ancestors');
        $c->where(array(
            'Ancestors.descendant' => $this->get('board'),
        ));
        $c->sortby('Ancestors.depth','DESC');
        $ancestors = $this->xpdo->getCollection('disBoard',$c);
        $trail = empty($defaultTrail) ? array(array(
            'url' => $this->xpdo->discuss->request->makeUrl(),
            'text' => $this->xpdo->getOption('discuss.forum_title'),
        )) : $defaultTrail;
        $category = false;
        foreach ($ancestors as $ancestor) {
            if (empty($category)) {
                $category = $ancestor->getOne('Category');
                if ($category) {
                    $trail[] = array(
                        'url' => $this->xpdo->discuss->request->makeUrl('',array('category' => $category->get('id'))),
                        'text' => $category->get('name'),
                    );
                }
            }
            $trail[] = array(
                'url' => $this->xpdo->discuss->request->makeUrl('board',array('board' => $ancestor->get('id'))),
                'text' => $ancestor->get('name'),
            );
        }
        $title = str_replace(array('[',']'),array('&#91;','&#93;'),$this->get('title'));
        $trail[] = array('text' => $title, 'active' => true);
        $trail = $this->xpdo->discuss->hooks->load('breadcrumbs',array(
            'items' => &$trail,
        ));
        $this->set('trail',$trail);
        return $trail;
    }

    /**
     * Build the CSS class for this thread
     *
     * @param string $defaultClass
     * @return array|string
     */
    public function buildCssClass($defaultClass = 'dis-normal-thread') {
        $class = array($defaultClass);
        $threshold = $this->xpdo->getOption('discuss.hot_thread_threshold',null,10);
        $participants = explode(',',$this->get('participants'));
        if (in_array($this->xpdo->discuss->user->get('id'),$participants) && $this->xpdo->discuss->user->isLoggedIn) {
            $class[] = $this->get('replies') < $threshold ? 'dis-my-normal-thread' : 'dis-my-veryhot-thread';
        } else {
            $class[] = $this->get('replies') < $threshold ? '' : 'dis-veryhot-thread';
        }
        $class = implode(' ',$class);
        $this->set('class',$class);
        return $class;
    }

    /**
     * Build the icon list for this thread
     *
     * @param array $icons
     * @return array|string
     */
    public function buildIcons($icons = array()) {
        if ($this->get('locked')) {
            $icons[] = '<div class="dis-thread-locked"></div>';
        }
        if ($this->xpdo->getOption('discuss.enable_sticky',null,true) && $this->get('sticky')) {
            $icons[] = '<div class="dis-thread-sticky"></div>';
        }
        $icons = implode("\n",$icons);
        $this->set('icons',$icons);
        return $icons;
    }

    /**
     * Up the view count and set last visited for the Thread.
     * @return boolean
     */
    public function view() {
        $lastViewed = false;

        /* prevent view pushing */
        $ip = $this->xpdo->discuss->getIp();
        if ($this->get('last_view_ip') == $ip) $lastViewed = true;

        /* up the view count for this thread */
        if (!$lastViewed) {
            $views = $this->get('views');
            $this->set('views',($views+1));
            $this->set('last_view_ip',$ip);
            $this->save(null,false);
            unset($views);
        }

        /* set last visited */
        if ($this->xpdo->discuss->user->get('user') != 0) {
            $this->xpdo->discuss->user->set('thread_last_visited',$this->get('id'));
            $this->xpdo->discuss->user->save();
        }

        if (!$lastViewed) {
            $this->xpdo->getCacheManager();
            $this->xpdo->cacheManager->delete('discuss/thread/'.$this->get('id'));
            $this->xpdo->cacheManager->delete('discuss/board/'.$this->get('board'));
            $this->xpdo->cacheManager->delete('discuss/board/'.$this->get('board').'/');
        }
        return true;
    }
    
    /**
     * Fetch all posts for this thread
     *
     * @param mixed $post A reference to a disPost or ID of disPost to start the posts from
     * @param array $options An array of options for sorting, limiting and display
     * @return array
     */
    public function fetchPosts($post = false,array $options = array()) {
        $response = array();
        $c = $this->xpdo->newQuery('disPost');
        $c->innerJoin('disThread','Thread');
        $c->where(array(
            'thread' => $this->get('id'),
        ));
        $cc = clone $c;
        $response['total'] = $this->xpdo->getCount('disPost',$cc);
        $flat = $this->xpdo->getOption('flat',$options,true);
        $limit = $this->xpdo->getOption('limit',$options,(int)$this->xpdo->getOption('discuss.post_per_page',$options, 10));
        $start = $this->xpdo->getOption('start',$options,0);
        if ($flat) {
            $sortBy = $this->xpdo->getOption('sortBy',$options,'createdon');
            $sortDir = $this->xpdo->getOption('sortDir',$options,'ASC');
            $c->sortby($this->xpdo->getSelectColumns('disPost','disPost','',array($sortBy)),$sortDir);
            if (empty($_REQUEST['print'])) {
                $c->limit($limit, $start);
            }
        } else {
            $c->sortby($this->xpdo->getSelectColumns('disPost','disPost','',array('rank')),'ASC');
        }

        if (!empty($post)) {
            if (!is_object($post)) {
                $post = $this->xpdo->getObject('disPost',$post);
            }
            if ($post) {
                $c->where(array(
                    'disPost.createdon:>=' => $post->get('createdon'),
                ));
            }
        }

        $c->bindGraph('{"Author":{},"EditedBy":{}}');
        //$c->prepare();
        //$cacheKey = 'discuss/thread/'.$thread->get('id').'/'.md5($c->toSql());
        $response['results'] = $this->xpdo->getCollectionGraph('disPost','{"Author":{},"EditedBy":{}}',$c);

        return $response;
    }

    /**
     * Move a thread to a new board
     *
     * @param int $boardId
     * @return boolean True if successful
     */
    public function move($boardId) {
        $oldBoard = $this->getOne('Board');
        $newBoard = is_object($boardId) && $boardId instanceof disBoard ? $boardId : $this->xpdo->getObject('disBoard',$boardId);
        if (!$oldBoard || !$newBoard) {
            return false;
        }
        $this->addOne($newBoard);
        if ($this->save()) {
            /* readjust all posts */
            $posts = $this->getMany('Posts');
            foreach ($posts as $post) {
                $post->set('board',$newBoard->get('id'));
                $post->save();
            }

            /* adjust old board topics/reply counts */
            $oldBoard->set('num_topics',$oldBoard->get('num_topics')-1);

            $replies = $oldBoard->get('num_replies') - $this->get('replies');
            $oldBoard->set('num_replies',$replies);

            $total_posts = $oldBoard->get('total_posts') - $this->get('replies') - 1;
            $oldBoard->set('total_posts',$total_posts);

            /* recalculate latest post */
            $oldBoardLastPost = $this->xpdo->getObject('disPost',array('id' => $oldBoard->get('last_post')));
            if ($oldBoardLastPost && $oldBoardLastPost->get('id') == $this->get('post_last')) {
                $newLastPost = $oldBoard->get2ndLatestPost();
                if ($newLastPost) {
                    $oldBoard->set('last_post',$newLastPost->get('id'));
                    $oldBoard->addOne($newLastPost,'LastPost');
                }
            }
            $oldBoard->save();

            /* adjust new board topics/reply counts */
            $newBoard->set('num_topics',$oldBoard->get('num_topics')-1);

            $replies = $newBoard->get('num_replies') + $this->get('replies');
            $newBoard->set('num_replies',$replies);

            $total_posts = $newBoard->get('total_posts') + $this->get('replies') + 1;
            $newBoard->set('total_posts',$total_posts);

            /* recalculate latest post */
            $newBoardLastPost = $this->xpdo->getObject('disPost',array('id' => $newBoard->get('last_post')));
            $thisThreadPost = $this->getOne('LastPost');
            if ($newBoardLastPost && $thisThreadPost && $newBoardLastPost->get('createdon') < $thisThreadPost->get('createdon')) {
                $newBoard->set('last_post',$thisThreadPost->get('id'));
                $newBoard->addOne($thisThreadPost,'LastPost');
            }
            $newBoard->save();

            /* Update ThreadRead board field */
            $this->xpdo->exec('UPDATE '.$this->xpdo->getTableName('disThreadRead').'
                SET '.$this->xpdo->escape('board').' = '.$newBoard->get('id').'
                WHERE '.$this->xpdo->escape('thread').' = '.$this->get('id').'
            ');

            /* clear caches */
            if (!defined('DISCUSS_IMPORT_MODE')) {
                $this->xpdo->getCacheManager();
                $this->xpdo->cacheManager->delete('discuss/thread/'.$this->get('id'));
                $this->xpdo->cacheManager->delete('discuss/board/'.$newBoard->get('id'));
                $this->xpdo->cacheManager->delete('discuss/board/'.$oldBoard->get('id'));
            }
        }
        return true;
    }

    /**
     * Determines if a thread has been auto-archived or not.
     * @return bool
     */
    public function isArchived() {
        $archived = false;
        $archiveAfter = $this->xpdo->getOption('discuss.archive_threads_after',null,0);
        if (!empty($archiveAfter) && $this->getOne('FirstPost')) {
            $diff = time() - strtotime($this->FirstPost->get('createdon'));
            if ($diff > ($archiveAfter * 24 * 60 * 60)) {
                $archived = true;
            }
        }
        return $archived;
    }

    /**
     * Determines if the active user can post or not
     *
     * @param xPDO $modx A reference to the modX object
     * @return bool
     */
    public static function canPostNew(xPDO &$modx) {
        if ($modx->discuss->user->isAdmin()) return true;
        return $modx->hasPermission('discuss.thread_create');
    }

    /**
     * Determines if the active user can post a reply or not
     * @return bool
     */
    public function canReply() {
        if ($this->xpdo->discuss->user->isAdmin()) return true;
        return !$this->isArchived() && $this->xpdo->hasPermission('discuss.thread_reply') && !$this->get('locked');
    }

    /**
     * Determines if the active user can post attachments to this thread
     * @return bool
     */
    public function canPostAttachments() {
        return $this->xpdo->discuss->user->isLoggedIn && $this->xpdo->hasPermission('discuss.thread_attach');
    }

    /**
     * Calculate the last post pagination page for a thread
     * @return int
     */
    public function calcLastPostPage() {
        $page = $this->get('last_post_page');
        if (empty($page)) {
            $page = 1;
            $replies = $this->get('last_post_replies');
            $perPage = $this->xpdo->getOption('discuss.post_per_page',null, 10);
            if ($replies > $perPage) {
                $page = ceil($replies / $perPage);
            }
            $this->set('last_post_page',$page);
        }
        return $page;
    }

    /**
     * Get the proper URL for the thread, optionally with the post anchor and page
     *
     * @param boolean $lastPost If true, will get URL for last Post of thread
     * @param array $params Any extra params to append to the thread
     * @param boolean $regenerate If true, will regenerate the url even if it has already been set
     * @return string
     */
    public function getUrl($lastPost = true,array $params = array(),$regenerate = false) {
        $url = $this->get('url');
        if (empty($url) || $regenerate || !empty($params)) {
            $view = 'thread/'.$this->get('id').'/'.$this->getUrlTitle();

            if ($lastPost) {
                $page = $this->calcLastPostPage();
                $sortDir = $this->xpdo->getOption('discuss.post_sort_dir',null,'ASC');
                if ($page > 1 && $sortDir == 'ASC') {
                    $params['page'] = $this->get('last_post_page');
                }
            }

            $url = $this->xpdo->discuss->request->makeUrl($view,$params);
            if ($this->get('post_id') && $lastPost) {
                $url .= '#dis-post-'.$this->get('post_id');
            }
            $this->set('url',$url);
        }
        return $url;
    }

    /**
     * Get the URL-friendly title of this thread
     * @return string
     */
    public function getUrlTitle() {
        $title = $this->get('title');
        if (empty($title)) {
            $post = $this->getOne('FirstPost');
            if ($post) {
                $title = $post->get('title');
                $this->set('title',$title);
                $this->save();
            }
        }

        if (!empty($title)) {
            $title = trim(preg_replace('/[^A-Za-z0-9-]+/', '-', strtolower($title)),'-');
        } else {
            $title = $this->get('id');
        }
        return $title;

    }

    /** can* methods */

    /**
     * Determines whether or not the current active user can stick the thread
     * @return bool
     */
    public function canStick() {
        return !$this->get('sticky') && $this->xpdo->hasPermission('discuss.thread_stick') &&
            ($this->isModerator() || $this->xpdo->discuss->user->isAdmin());
    }
    /**
     * Determines whether or not the current active user can unstick the thread
     * @return bool
     */
    public function canUnstick() {
        return $this->get('sticky') && $this->xpdo->hasPermission('discuss.thread_unstick') &&
            ($this->isModerator() || $this->xpdo->discuss->user->isAdmin());
    }
    /**
     * Determines whether or not the current active user can lock the thread
     * @return bool
     */
    public function canLock() {
        return !$this->get('locked') && $this->xpdo->hasPermission('discuss.thread_lock') &&
            ($this->isModerator() || $this->xpdo->discuss->user->isAdmin());
    }
    /**
     * Determines whether or not the current active user can unlock the thread
     * @return bool
     */
    public function canUnlock() {
        return $this->get('locked') && $this->xpdo->hasPermission('discuss.thread_unlock') &&
            ($this->isModerator() || $this->xpdo->discuss->user->isAdmin());
    }
    /**
     * Determines whether or not the current active user can move the thread
     * @return bool
     */
    public function canMove() {
        return $this->xpdo->hasPermission('discuss.thread_move') &&
            ($this->isModerator() || $this->xpdo->discuss->user->isAdmin());
    }
    /**
     * Determines whether or not the current active user can remove the thread
     * @return bool
     */
    public function canRemove() {
        return $this->xpdo->hasPermission('discuss.thread_remove') &&
            ($this->isModerator() || $this->xpdo->discuss->user->isAdmin());
    }
    /**
     * Determines whether or not the current active user can print the thread
     * @return bool
     */
    public function canPrint() {
        return $this->xpdo->hasPermission('discuss.thread_print');
    }
    /**
     * Determines whether or not the current active user can subscribe to the thread
     * @return bool
     */
    public function canSubscribe() {
        return !$this->hasSubscription() && $this->xpdo->hasPermission('discuss.thread_subscribe');
    }
    /**
     * Determines whether or not the current active user can unsubscribe the thread
     * @return bool
     */
    public function canUnsubscribe() {
        return $this->hasSubscription() && $this->xpdo->hasPermission('discuss.thread_subscribe');
    }

    /**
     * See if the active user can modify a post in this thread
     * @param int $postId
     * @return bool
     */
    public function canModifyPost($postId) {
        return $this->isModerator();
    }

    /**
     * See if the active user can remove a post in this thread
     * @param int $postId
     * @return bool
     */
    public function canRemovePost($postId) {
        return $this->isModerator();
    }
    
    /**
     * Check to see if active user is a Moderator for this thread's board
     *
     * @return bool True if they are a moderator
     */
    public function isModerator() {
        if ($this->xpdo->discuss->user->isGlobalModerator() || $this->xpdo->discuss->user->isAdmin()) return true;

        $moderator = $this->xpdo->getCount('disModerator',array(
            'user' => $this->xpdo->discuss->user->get('id'),
            'board' => $this->get('board'),
        ));
        return $moderator > 0;
    }

    /** process views for derivative thread types */

    /**
     * Prepares the thread view, useful for extra processing when using derivative thread types
     * 
     * @param array $postArray
     * @return array
     */
    public function prepareThreadView(array $postArray) { return $postArray; }


    /**
     * Handle actions on thread view
     *
     * @param array $scriptProperties
     * @return boolean
     */
    public function handleThreadViewActions(array $scriptProperties) {
        if (!$this->xpdo->discuss->user->isLoggedIn) return false;

        /* mark unread if user clicks mark unread */
        if (isset($scriptProperties['unread'])) {
            if ($this->unread($this->xpdo->discuss->user->get('id'))) {
                $this->xpdo->sendRedirect($this->xpdo->discuss->request->makeUrl('board',array('board' => $this->get('board'))));
            }
        }
        if (!empty($scriptProperties['sticky']) && $this->canStick()) {
            if ($this->stick()) {
                $this->xpdo->sendRedirect($this->xpdo->discuss->request->makeUrl('board',array('board' => $this->get('board'))));
            }
        }
        if (isset($scriptProperties['sticky']) && $scriptProperties['sticky'] == 0 && $this->canUnstick()) {
            if ($this->unstick()) {
                $this->xpdo->sendRedirect($this->xpdo->discuss->request->makeUrl('board',array('board' => $this->get('board'))));
            }
        }
        if (!empty($scriptProperties['lock']) && $this->canLock()) {
            if ($this->lock()) {
                $this->xpdo->sendRedirect($this->xpdo->discuss->request->makeUrl('board',array('board' => $this->get('board'))));
            }
        }
        if (isset($scriptProperties['lock']) && $scriptProperties['lock'] == 0 && $this->canUnlock()) {
            if ($this->unlock()) {
                $this->xpdo->sendRedirect($this->xpdo->discuss->request->makeUrl('board',array('board' => $this->get('board'))));
            }
        }
        if (!empty($scriptProperties['subscribe']) && $this->canSubscribe()) {
            if ($this->addSubscription($this->xpdo->discuss->user->get('id'))) {
                $this->xpdo->sendRedirect($this->getUrl());
            }
        }
        if (!empty($scriptProperties['unsubscribe']) && $this->canUnsubscribe()) {
            if ($this->removeSubscription($this->xpdo->discuss->user->get('id'))) {
                $this->xpdo->sendRedirect($this->getUrl());
            }
        }

        return true;
    }

    /**
     * Prepare the aggregate composition of the action buttons for a post
     * @param array $postArray
     * @param string $defaultAvailableActions
     * @return array
     */
    public function aggregateThreadActionButtons(array $postArray = array(),$defaultAvailableActions = 'mark_as_answer,reply,quote,modify,remove,spam') {
        $actions = array();
        $availableActions = $this->xpdo->getOption('discuss.thread_actionbutton_order',null,$defaultAvailableActions);
        $availableActions = explode(',',$availableActions);
        $availableActions = array_reverse($availableActions);
        foreach ($availableActions as $availableAction) {
            if (!empty($postArray['action_'.$availableAction])) {
                $actions[] = $postArray['action_'.$availableAction];
            }
        }
        return $actions;
    }
}