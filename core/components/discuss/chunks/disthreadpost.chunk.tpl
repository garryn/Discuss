<div class="dis-post">
	<div class="dis-post-header">
		<div class="dis-post-actions">
		    <a href="javascript:void([[+id]]);" class="dis-post-remove" onclick="DISThread.removePost([[+id]],[[+parent]],'[[~[[++discuss.board_resource]]]]?board=[[+board]]');">[[%discuss.remove]]</a>
		    <a href="[[~[[++discuss.modify_post_resource]]]]?post=[[+id]]" class="dis-post-modify">[[%discuss.modify]]</a>
		    <a href="javascript:void([[+id]]);" class="dis-post-reply-btn" onclick="DISThread.showReplyForm([[+id]]);">[[%discuss.reply]]</a>
		</div>
		<h3 class="dis-post-title" post="[[+id]]">[[+title]]</h3>
		<div class="dis-post-author">
		      <div class="dis-author">- [[%discuss.post_author_short? &user=`[[+username]]` &date=`[[+createdon:strtotime:date=`[[++discuss.date_format]]`]]`]]</div>
		      <div class="dis-author dis-hidden">
		          [[+author_avatar]]
		          <span class="right">
		          [[+createdon:strtotime:date=`[[++discuss.date_format]]`]]
		          <br />[[+author_email]]
		          </span>
		          <span>
		          [[+username]] <em>[[+author_title]]</em><br />
		          [[%discuss.posts]]: <span class="dis-author-post-count">[[+author_posts]]</span>
		          </span>
		          <br class="clear" />
                  <div class="dis-signature">[[+author_signature]]</div>
		      </div>
	    </div>  
		
	</div>
	<div class="dis-post-ct" id="dis-thread-ct-[[+id]]">
		<div class="dis-post-body">[[+content]]</div>
		<div class="dis-post-footer">
              <a href="javascript:void([[+id]]);">[[%discuss.report_to_mod]]</a>
              - <a href="javascript:void([[+id]]);">[[+ip]]</a>
		</div>
        <div class="dis-post-reply" id="dis-post-reply-[[+id]]"></div>
	</div>
</div>