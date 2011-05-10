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
 * Mark Post as Spam page
 *
 * @package discuss
 */
/* get thread root */
$post = $modx->getObject('disPost',$scriptProperties['post']);
if ($post == null) $modx->sendErrorPage();
$discuss->setPageTitle($modx->lexicon('discuss.post_spam_header',array('title' => $post->get('title'))));
$thread = $modx->call('disThread', 'fetch', array(&$modx,$post->get('thread')));
if (empty($thread)) { $modx->sendErrorPage(); }

$isModerator = $modx->getCount('disModerator',array(
    'user' => $discuss->user->get('id'),
    'board' => $post->get('board'),
)) > 0 ? true : false;

$canRemovePost = $discuss->user->get('id') == $post->get('author') || $isModerator || $discuss->user->isAdmin();
if (!$canRemovePost) {
    $modx->sendErrorPage();
}


if (!$post->remove(array(),true,true)) {
    $modx->log(modX::LOG_LEVEL_ERROR,'[Discuss] Could not remove post: '.print_r($post->toArray(),true));
}

if ($thread->get('post_first') == $post->get('id')) {
    $redirectTo = $discuss->url.'board/?board='.$post->get('board');
} else {
    $redirectTo = $discuss->url.'thread/?thread='.$post->get('thread');
}
$modx->sendRedirect($redirectTo);