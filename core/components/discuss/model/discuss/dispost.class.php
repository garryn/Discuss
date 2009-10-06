<?php
/**
 * Represents any post made on the forum, including Threads, which are Posts
 * with a parent of 0.
 *
 * @package discuss
 */
class disPost extends xPDOSimpleObject {
    function disPost(& $xpdo) {
        $this->__construct($xpdo);
    }
    function __construct(& $xpdo) {
        parent :: __construct($xpdo);
    }

    /**
     * Overrides xPDOObject::save to handle closure table edits.
     *
     * @TODO: add code for moving posts to different parents.
     *
     * {@inheritDoc}
     */
    function save($cacheFlag = null) {
        $new = $this->isNew();
        $saved = parent::save($cacheFlag);

        if ($saved && $new) {
            $id = $this->get('id');
            $parent = $this->get('parent');

            /* create self closure */
            $cl = $this->xpdo->newObject('disPostClosure');
            $cl->set('ancestor',$id);
            $cl->set('descendant',$id);
            if ($cl->save() === false) {
                $this->remove();
                return false;
            }

            /* create closures and calculate rank */
            $tableName = $this->xpdo->getTableName('disPostClosure');
            $c = $this->xpdo->newQuery('disPostClosure');
            $c->where(array(
                'descendant' => $parent,
                'ancestor:!=' => 0,
            ));
            $c->sortby('depth','DESC');
            $gparents = $this->xpdo->getCollection('disPostClosure',$c);
            $cgps = count($gparents);
            $gps = array();
            $i = $cgps;
            foreach ($gparents as $gparent) {
                $gps[] = str_pad($gparent->get('ancestor'),10,'0',STR_PAD_LEFT);
                $obj = $this->xpdo->newObject('disPostClosure');
                $obj->set('ancestor',$gparent->get('ancestor'));
                $obj->set('descendant',$id);
                $obj->set('depth',$i);
                $obj->save();
                $i--;
            }
            $gps[] = str_pad($id,10,'0',STR_PAD_LEFT); /* add self closure too */

            /* add root closure */
            $cl = $this->xpdo->newObject('disPostClosure');
            $cl->set('ancestor',0);
            $cl->set('descendant',$id);
            $cl->set('depth',$cgps);
            $cl->save();

            /* set rank */
            $rank = implode('-',$gps);
            $this->set('rank',$rank);
            $this->save();

            /* up author post count */
            $profile = $this->getOne('AuthorProfile');
            $profile->set('posts',($profile->get('posts')+1));
            $profile->save();

            /* adjust board total replies/posts, and last post */
            $board = $this->getOne('Board');
            $board->set('last_post',$this->get('id'));
            $board->set('total_posts',$board->get('total_posts')+1);
            if ($this->get('parent') != 0) {
                $board->set('num_replies',$board->get('num_replies')+1);
            } else {
                $board->set('num_topics',$board->get('num_topics')+1);
            }
            $board->save();

            /* adjust forum activity */
            $now = date('Y-m-d');
            $activity = $this->xpdo->getObject('disForumActivity',array(
                'day' => $now,
            ));
            if ($this->get('parent') != 0) {
                $activity->set('replies',$activity->get('replies')+1);
            } else {
                $activity->set('topics',$activity->get('topics')+1);
            }
        }
        return $saved;
    }

    /**
     * Overrides xPDOObject::remove to handle closure tables, post counts, fix
     * the forum activity and user profile counts.
     *
     * {@inheritDoc}
     */
    function remove($ancestors= array ()) {
        $profile = $this->getOne('AuthorProfile');
        $board = $this->getOne('Board');
        $parent = $this->get('parent');

        $removed = parent::remove();
        if ($removed) {
            /* decrease profile posts */
            $profile->set('posts',($profile->get('posts')-1));
            $profile->save();

            /* fix board last post */
            $c = $this->xpdo->newQuery('disPost');
            $c->where(array(
                'board' => $board->get('id'),
            ));
            $c->sortby('createdon','DESC');
            $c->limit(1);
            $latestPost = $this->xpdo->getObject('disPost',$c);
            if ($latestPost) {
                $board->set('last_post',$latestPost->get('id'));
            } else {
                $board->set('last_post',0);
            }
            /* fix total post/replies/topics counts */
            $board->set('total_posts',($board->get('total_posts')-1));
            if ($parent == 0) {
                $board->set('num_topics',($board->get('num_topics')-1));
            } else {
                $board->set('num_replies',($board->get('num_replies')-1));
            }
            $board->save();
        }
        return $removed;
    }

    public function getContent() {
        $message = $this->get('message');

        $tags = array(
            '<','>',
            '[b]','[/b]',
            '[i]','[/i]',
            '[img]','[/img]',
            '[code]','[/code]',
            '[quote]','[/quote]',
            '[s]','[/s]',
            '[url="','[/url]',
            '[email="','[/email]',
            '[hr]',
            '[list]','[/list]','[li]','[/li]',
            '"]',
        );
        if ($this->xpdo->getOption('discuss.bbcode_enabled',null,true)) {
            $message = str_replace($tags,array(
                '&lt;','&gt;',
                '<strong>','</strong>',
                '<em>','</em>',
                '<img src="','">',
                '<div class="dis-code"><h5>Code</h5><pre>','</pre></div>',
                '<div class="dis-quote"><h5>Quote</h5><div>','</div></div>',
                '<span class="dis-strikethrough">','</span>',
                '<a href="','</a>',
                '<a href="mailto:','</a>',
                '<hr />',
                '<ul>','</ul>','<li>','</li>',
                '">',
            ),$message);
        } else {
            $message = str_replace($tags,'',$message);
        }

        return $this->_nl2br2($message);

    }

    private function _nl2br2($str) {
        $str = str_replace("\r", '', $str);
        return preg_replace('/(?<!>)\n/', "<br />\n", $str);
    }

    /**
     * Gets the Thread root post of this current Post; if this Post is the
     * thread root, returns itself.
     *
     * @access public
     * @return disPost
     */
    public function getThreadRoot() {
        if ($this->get('parent') == 0) {
            return $this;
        }
        $c = $this->xpdo->newQuery('disPost');
        $c->innerJoin('disPostClosure','Ancestors');
        $c->innerJoin('disPost','Thread','Thread.id = Ancestors.ancestor');
        $c->where(array(
            'Ancestors.descendant' => $this->get('id'),
            'Ancestors.ancestor:!=' => $this->get('id'),
            'Thread.parent' => 0,
        ));
        $thread = $this->xpdo->getObject('disPost',$c);

        return $thread;
    }


    public function getViewing() {
        if (!$this->xpdo->getOption('discuss.show_whos_online',null,true)) return '';

        $c = $this->xpdo->newQuery('disSession');
        $c->select('disSession.id,GROUP_CONCAT(CONCAT_WS(":",User.id,User.username) SEPARATOR ",") AS readers');
        $c->innerJoin('modUser','User');
        $c->where(array(
            'disSession.place' => 'thread:'.$this->get('id'),
        ));
        $c->groupby('disSession.user');
        $members = $this->xpdo->getObject('disSession',$c);
        if ($members) {
            $readers = explode(',',$members->get('readers'));
            $members = '';
            foreach ($readers as $reader) {
                $r = explode(':',$reader);
                $members .= '<a href="[[~[[++discuss.user_resource]]]]?user='.$r[0]
                    .'">'.$r[1].'</a>,';
            }
            $members = trim($members,',');
        } else { $members = '0 members'; }

        $c = $this->xpdo->newQuery('disSession');
        $c->where(array(
            'place' => 'thread:'.$this->get('id'),
            'user' => 0,
        ));
        $guests = $this->xpdo->getCount('disSession',$c);

        return $members.' and '.$guests.' guests are viewing this thread.';
    }
}