<?php
/*
Plugin Name:    Infomed XML-RPC Methods
Description:    Add methods to get blogs, users of blog by role, recent published posts, a post by id, categories, published posts by category, to perform simple and advanced search of published posts. All plugin methods allow paginate the results.
Version:        0.1.1
Author:         Yazna Garcia Vega
Author Email:   yazna@infomed.sld.cu
 */
include_once(ABSPATH . WPINC . '/class-IXR.php');

add_filter('disable_captions', create_function('$a','return true;'));
add_filter( 'xmlrpc_methods', 'add_info_xmlrpc_methods' );

add_action ( 'wpmu_new_blog', 'infoarticles_blog_config', 10, 2);

function infoarticles_blog_config( $blog_id, $user_id ) {
	switch_to_blog($blog_id);

    //habilitar xmlrpc, que en el schema es 0
	update_option('enable_xmlrpc', 1);
}

function add_info_xmlrpc_methods( $methods ) {

    $methods['info.getBlogs'] = 'info_getBlogs';
    $methods['info.getUsers_byBlogId'] = 'info_getUsers_byBlogId';
    $methods['info.getUsers_byBlogUrl'] = 'info_getUsers_byBlogUrl';
    $methods['info.getRecentPosts'] = 'info_getRecentPosts';
    $methods['info.getRecentPostTitles'] = 'info_getRecentPostTitles';
    $methods['info.getPost'] = 'info_getPost';
    $methods['info.login_pass'] = 'info_login_pass_ok';
	$methods['info.getBlog_Id'] = 'info_getBlog_Id';
	$methods['info.getCategories'] = 'info_getCategories';
	$methods['info.getPosts_byCategory'] = 'info_getPosts_byCategory';
	$methods['info.simpleSearch'] = 'info_simpleSearch';
	$methods['info.advancedSearch'] = 'info_advancedSearch';
	$methods['info.getAttachments'] = 'info_getAttachments';

    return $methods;
}

/* info.getBlogs to get blogs and their users by role
 * args[0] (int) start: the first blog to return
 * args[1] (int) limit: the maximum number of blogs to return
 * args[2] (string) username of wp mu
 * args[3] (string) the password that accompanies username
 * args[4] (int) id of role (0: dont recover users, 1: administrator, 2: editor, 3: author,
 *                                                  4: contributor, 5: subscriber)
 * return array with keys: total - total of blogs
 *                         list - array of blogs ordered desc by registered date
 *								 [$blog_id](['name'], ['title'], ['email'], ['postcount'])),
 *                                it may includes users by role of each blog
 *                                ([$blog_id]['users'])
 */
function info_getBlogs($args) {
	global $wpdb, $wpmuBaseTablePrefix;
    global $this_error;

	$start = (int)$args[0];
	$num = (int)$args[1];

	$username	= $args[2];
	$password	= $args[3];
	$get_users = (int)$args[4];

	if(!info_login_pass_ok($username, $password)) {
		return($this_error);
	}

	$total =  $wpdb->get_var( "SELECT count(*) FROM $wpdb->blogs WHERE site_id = '$wpdb->siteid' AND public = '1' AND archived = '0' AND mature = '0' AND spam = '0' AND deleted = '0' ");

	do_action('xmlrpc_call', 'info.getBlogs');

	$blogs = $wpdb->get_results( "SELECT blog_id, domain, path FROM $wpdb->blogs WHERE site_id = '$wpdb->siteid' AND public = '1' AND archived = '0' AND mature = '0' AND spam = '0' AND deleted = '0' ORDER BY registered DESC LIMIT $start,$num", ARRAY_A );

	if( is_array( $blogs ) ) {
		while( list( $key, $details ) = each( $blogs ) ) {
			$blog_list[ $details[ 'blog_id' ] ] = $details;
			switch_to_blog($details[ 'blog_id' ]);
			$piezas = explode ("/", $details[ 'path' ]);
			$name = $piezas[count($piezas)-2];
			$blog_list[ $details[ 'blog_id' ] ][ 'name' ] = $name;
			$blog_list[ $details[ 'blog_id' ] ][ 'title' ] = get_option('blogname');
			$blog_list[ $details[ 'blog_id' ] ][ 'email' ] = get_option('admin_email');
			//total of published posts at blog
			$blog_list[ $details[ 'blog_id' ] ][ 'postcount' ] = $wpdb->get_var( "SELECT count(*) FROM " . $wpmuBaseTablePrefix . $details[ 'blog_id' ] . "_posts WHERE post_status='publish' AND post_type='post'" );

	        if( $get_users ) {
			    $blog_list[ $details[ 'blog_id' ] ][ 'users' ] = info_getUsers_byBlogId( array($details[ 'blog_id' ], $username, $password, $get_users));
			}
		}

		unset( $blogs );
		$blogs = $blog_list;
	}
	return array('total' => $total, 'list' => $blogs);
   }

/* info_getUsers_byBlogId to get Blog users by blogid and role id
 * args[0] (int) blogid
 * args[1] (string) username of wp mu
 * args[2] (string) the password that accompanies username
 * args[3] (int) id of role
 * return array of user data (mail, login, firstname, secondname)
 */
function info_getUsers_byBlogId($args) {
	global $wpdb, $wpmuBaseTablePrefix;
    global $this_error;

	$blog_id = (int)$args[0];
	$username	= $args[1];
	$password	= $args[2];
	$rol_id	= (int)$args[3];

	if(!info_login_pass_ok($username, $password)) {
		return($this_error);
	}

	do_action('xmlrpc_call', 'info.getUsers_byBlogId');

	switch ($rol_id) {
	  case 1:
	    $like = '%dministrator%';
		break;
	  case 2:
	    $like = '%editor%';
		break;
	  case 3:
	    $like = '%author%';
		break;
	  case 4:
	    $like = '%contributor%';
		break;
	  case 5:
	    $like = '%subscriber%';
		break;
	}
    $users = $wpdb->get_results( "SELECT user_id FROM $wpdb->usermeta WHERE meta_key = '" . $wpmuBaseTablePrefix . $blog_id . "_capabilities' AND meta_value LIKE '". $like ."'", ARRAY_A );

	if( is_array( $users ) ) {
		$user_list = array();
		$i = 0;
		while( list( $key_user, $details_user ) = each( $users ) ) {
			$mail_login = $wpdb->get_results( "SELECT user_email, user_login FROM $wpdb->users WHERE ID =" . $details_user[ 'user_id' ] );
		    $user_list[$i]['mail'] = $mail_login[0]->user_email;
			$user_list[$i]['login'] = $mail_login[0]->user_login;

			$user_list[$i]['firstname'] = $wpdb->get_var( "SELECT meta_value FROM $wpdb->usermeta WHERE user_id =" . $details_user[ 'user_id' ] . " AND meta_key = 'first_name'" );
			$user_list[$i]['secondname'] = $wpdb->get_var( "SELECT meta_value FROM $wpdb->usermeta WHERE user_id =" . $details_user[ 'user_id' ] . " AND meta_key = 'second_name'" );

			$i += 1;
		}
		unset( $users );
	}
	return $user_list;
   }

/* info_getUsers_by_blogdomain to get Blog users by blog url and role id
 * args[0] (string) Blog Url
 * args[1] (string) username of wp mu
 * args[2] (string) the password that accompanies username
 * args[3] (int) id of role
 * return array of user data (mail, login, firstname, secondname)
 */
function info_getUsers_byBlogUrl($args) {
    global $wpdb;

	$blog_url = $args[0];
	$username	= $args[1];
	$password	= $args[2];
	$rol_id	= (int)$args[3];

	do_action('xmlrpc_call', 'info.getUsers_byBlogUrl');

 	$uri = parse_url($blog_url);
	$blog_domain = $uri['host'];
	$blog_path = $uri['path'];

	$blog_id = $wpdb->get_var("SELECT blog_id FROM ".$wpdb->blogs." WHERE domain = '".$blog_domain."' AND path = '".$blog_path."' AND site_id = '$wpdb->siteid'");

    if( $blog_id != false ) {
	  return info_getUsers_byBlogId(array($blog_id, $username, $password, $rol_id));
	}
	else {
		return false;
	}
}

/* info.getRecentPosts: to get published posts ordered desc by date
 * args[0] (int) start: the first post to return
 * args[1] (int) num_posts: the maximum number of posts to return, (0 to return all)
 * args[2] (int) attachments: 1 to return attachment links, 0 dont return attachments (default)
 * return array with keys:
 *    - total: total of published posts
 *    - list: array of posts (dateCreated, userid, postid, title, categories, resume, content, wp_author_display_name)
 */
function info_getRecentPosts($args) {
    global $wpdb;
    global $this_error;

	infoxmlrpc_escape($args);

	$start   = (int) $args[0];
	$num_posts   = (int) $args[1];

    $attachments = 0;
	if (isset($args[2])) {
	  $attachments = $args[2];
	}

	$limit = "";
    if ($num_posts>0) {
    	$limit = " LIMIT $start, $num_posts";
    }

    $sql = "SELECT count(*) FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'publish' AND post_title != ''";
    $total = $wpdb->get_var($sql);

    $sql = "SELECT ID, post_title, post_content, post_author, post_excerpt, post_date FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'publish' AND post_title != '' ORDER BY post_date DESC $limit";
    $posts_list = $wpdb->get_results($sql,ARRAY_A);

	do_action('xmlrpc_call', 'info.getRecentPosts');

	if (!$posts_list) {
		$this_error = new IXR_Error(500, __('Either there are no posts, or something went wrong.'));
		return($this_error);
	}

	foreach ($posts_list as $entry) {

		$post_date = mysql2date('Ymd\TH:i:s', $entry['post_date']);

		$categories = array();
		$catids = wp_get_post_categories($entry['ID']);
		foreach($catids as $catid) {
			$categories[] = get_cat_name($catid);
		}

		$post = get_extended($entry['post_content']);

		// Get the post author info.
		$author = get_userdata($entry['post_author']);
        $autor_name = $author->display_name;

		$id = $entry['ID'];
		$link = post_permalink($id);

		if ($attachments) {
		  $ret_att = info_getAttachments($id);
	    }
		else {
		  $ret_att = NULL;
	    }

		$resume = $entry['post_excerpt'] ? $entry['post_excerpt'] : ($post['extended'] ? $post['main'] : '');
		$recent_posts[] = array(
			'postid' => $entry['ID'],
			'dateCreated' => new IXR_Date($post_date),
			'userid' => $entry['post_author'],
			'title' => $entry['post_title'],
			'link' => $link,
			'att_links' => $ret_att,
			'resume' => $resume ? wpautop($resume) : '',
			'content' => wpautop($post['main'].' '.$post['extended']),
			'categories' => $categories,
			'wp_author_display_name' => $autor_name
		);
	}

	/*$recent_posts = array();
	$total_struct = count($struct);
	for ($j=0; $j<$total_struct; $j++) {
		array_push($recent_posts, $struct[$j]);
	}*/

	return array('total' => $total, 'list' => $recent_posts);
}

/* info.getRecentPostTitles: to get titles of published posts, ordered desc by date
 * args[0] (int) start: the first post to return
 * args[1] (int) num_posts: the maximum number of posts to return, (0 to return all)
 * args[2] (int) attachments: 1 to return attachment links, 0 dont return attachments (default)
 * return array with keys:
 *    - total: total of published posts
 *    - list: array of posts (dateCreated, userid, postid, title)
 */
function info_getRecentPostTitles($args) {
	global $wpdb, $this_error;

	infoxmlrpc_escape($args);

	$start   = (int) $args[0];
	$num_posts   = (int) $args[1];

    $attachments = 0;
	if (isset($args[2])) {
	  $attachments = $args[2];
	}

	$limit = "";
    if ($num_posts>0) {
    	$limit = " LIMIT $start, $num_posts";
    }

    $sql = "SELECT count(*) FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'publish' AND post_title != ''";
    $total = $wpdb->get_var($sql);

    $posts_list = array();
	$sql = "SELECT ID, post_title, post_author, post_date FROM $wpdb->posts WHERE post_type = 'post' AND post_status = 'publish' AND post_title != '' ORDER BY post_date DESC $limit";
    $posts_list = $wpdb->get_results($sql,ARRAY_A);

	do_action('xmlrpc_call', 'info.getRecentPostTitles');

	if (!$posts_list) {
		$this_error = new IXR_Error(500, __('Either there are no posts, or something went wrong.'));
		return($this_error);
	}

	foreach ($posts_list as $entry) {
		$post_date = mysql2date('Ymd\TH:i:s', $entry['post_date']);

		$id = $entry['ID'];
		$link = post_permalink($id);

		if ($attachments) {
		  $ret_att = info_getAttachments($id);
	    }
		else {
		  $ret_att = NULL;
	    }

		$recientes[] = array(
			'dateCreated' => new IXR_Date($post_date),
			'userid' => $entry['post_author'],
			'postid' => $entry['ID'],
			'title' => $entry['post_title'],
			'link' => $link,
			'att_links' => $ret_att,
		);
	}

	/*$recientes = array();
	$end = count($struct);
	for ($j=0; $j < $end; $j++) {
		array_push($recientes, $struct[$j]);
	}*/
	return array('total' => $total, 'list' => $recientes);
}

/* info.getPost: to get a post by id
 * args[0] (int) post_ID: id of post to recover
 * args[1] (int) attachments: 1 to return attachment links, 0 dont return attachments (default)
 * return: array with post data (dateCreated, userid, postid, description, title, link, permaLink, categories, mt_excerpt, mt_text_more, wp_slug, wp_password, wp_author_id, wp_author_display_name)
 *         or error if post is not published
 */
function info_getPost($args) {

	infoxmlrpc_escape($args);

	if (is_array($args)) {
	  $post_ID     = (int) $args[0];
      $attachments = 0;
	  if (isset($args[1])) {
	    $attachments = $args[1];
	  }
	}
	else
	  $post_ID     = $args;


	do_action('xmlrpc_call', 'info.getPost');

	$entry = wp_get_single_post($post_ID, ARRAY_A);

	// Only published post
	if( $entry['post_status'] != 'publish' ) {
		return new IXR_Error(999, __('Sorry, post is not published.'));
	}

	if ($entry['post_date'] != '') {
		$post_date = mysql2date('Ymd\TH:i:s', $entry['post_date']);

		$categories = array();
		$catids = wp_get_post_categories($post_ID);
		foreach($catids as $catid)
			$categories[] = get_cat_name($catid);

		$post = get_extended($entry['post_content']);

		$id = $entry['ID'];
		$link = post_permalink($id);

		if ($attachments) {
		  $ret_att = info_getAttachments($id);
	    }
		else {
		  $ret_att = NULL;
	    }

		// Get the author info.
		$author = get_userdata($entry['post_author']);

		$resume = $entry['post_excerpt'] ? $entry['post_excerpt'] : ($post['extended'] ? $post['main'] : '');

		$resp = array(
			'dateCreated' => new IXR_Date($post_date),
			'userid' => $entry['post_author'],
			'postid' => $entry['ID'],
			'title' => $entry['post_title'],
			'link' => $link,
			'att_links' => $ret_att,
			'categories' => $categories,
			'resume' => $resume ? wpautop($resume) : '',
			'content' => wpautop($post['main'].' '.$post['extended']),
			'wp_slug' => $entry['post_name'],
			'wp_password' => $entry['post_password'],
			'wp_author_id' => $author->ID,
			'wp_author_display_name'	=> $author->display_name,
		);

		return $resp;
	} else {
		return new IXR_Error(404, __('Sorry, no such post.'));
	}
}


/* info.getBlog_Id: receives a blog url and return blog id
 * args[0] (string) Blog Url
 * return: blog id
 */
function info_getBlog_Id($args) {
    global $wpdb;

	$blog_url = $args;

	do_action('xmlrpc_call', 'info.getBlog_Id');

 	$uri = parse_url($blog_url);
	$blog_domain = $uri['host'];
	$blog_path = $uri['path'];

	$blog_id = $wpdb->get_var("SELECT blog_id FROM ".$wpdb->blogs." WHERE domain = '".$blog_domain."' AND path = '".$blog_path."' AND site_id = '$wpdb->siteid'");

    if( $blog_id != false ) {
	  return $blog_id;
	}
	else {
		return false;
	}
}

/* info.getCategories: to get categories of a blog
 * return array with keys:
 *    - total: total of categories
 *    - list: array of categories (cat_ID, cat_name)
 */
function info_getCategories($args) {
	global $this_error;

	do_action('xmlrpc_call', 'info.getCategories');

	$cat_list = get_categories();

	if (!$cat_list) {
		$this_error = new IXR_Error(1000, __('Either there are no categories, or something went wrong.'));
		return($this_error);
	}

	foreach ($cat_list as $cat) {
		$categories[] = array(
			'cat_ID' => $cat->cat_ID, //este atributo esta en todas las versiones
			'cat_name' => $cat->cat_name, //este atributo esta en todas las versiones
		);
	}
    $total = count($categories);
	return array('total' => $total, 'list' => $categories);
}

/* info.getPosts_byCategory: to get titles of published posts of the category
 * args[0] (int) cat_ID: category id
 * args[1] (int) offset: the first post to return
 * args[2] (int) posts_per_page: the maximum number of posts to return (-1 to return all)
 * args[3] (int) full_post: 1 -> to return full posts (content), 0 -> to return post titles
 * args[4] (int) attachments: 1 to return attachment links, 0 dont return attachments (default)
 * return array with keys:
 *    - total: total of published posts with the category
 *    - list: array of posts; if titles=1 (userid, postid, title, link),
 *                            else (dateCreated, userid, postid, title, link)
 */
function info_getPosts_byCategory($args) {
	global $this_error;

	$r['cat'] = (int) $args[0];
	$r['offset'] = (int) $args[1];
	$r['posts_per_page'] = (int) $args[2];//pasar -1 si se quieren todos

    $full_post = 1;
	if (isset($args[3])) {
	  $full_post = $args[3];
	}

    $attachments = 0;
	if (isset($args[4])) {
	  $attachments = $args[4];
	}

	do_action('xmlrpc_call', 'info.getPosts_byCategory');

	$get_posts = new WP_Query;
	$posts_list = $get_posts->query($r);

	if (!$posts_list) {
		$this_error = new IXR_Error(501, __('Either there are no posts with that category, or something went wrong.'));
		return($this_error);
	}

	foreach ($posts_list as $entry) {
	    $post_date = mysql2date('Ymd\TH:i:s', $entry->post_date);
		$id = $entry->ID;
		$link = post_permalink($id);

		if ($attachments) {
		  $ret_att = info_getAttachments($id);
	    }
		else {
		  $ret_att = NULL;
	    }

		if($full_post) {
		  $post = get_extended($entry->post_content);
          $content = wpautop($post['main'] .' '. $post['extended']);//para que salgan bien los cambios de linea en el cliente
	    }
		else {
		  $content = '';
	    }
	    $author = get_userdata($entry->post_author);

		$resume = $entry->post_excerpt ? $entry->post_excerpt : ($post['extended'] ? $post['main'] : '');
	    $posts_cat[] = array(
		  'dateCreated' => new IXR_Date($post_date),
		  'userid' => $entry->post_author,
		  'postid' => $entry->ID,
		  'title' => $entry->post_title,
		  'link' => $link,
		  'att_links' => $ret_att,
		  'resume' => $resume ? wpautop($resume) : '',
		  'content' => $content,
		  'wp_author_display_name' => $author->display_name,
		);
	}

    $total = $get_posts->found_posts;
	return array('total' => $total, 'list' => $posts_cat);
}


/* info.simpleSearch: search published posts with the text
 * args[0] (string) text to search
 * args[1] (int) start: the first post to return
 * args[2] (int) limit: the maximum number of posts to return
 * args[3] (int) full_post: 1 -> to return full posts (content), 0 -> to return post titles
 * args[4] (int) attachments: 1 to return attachment links, 0 dont return attachments (default)
 * return arreglo asociativo de:
 *    - total: total of published posts with the text
 *    - list: array of posts (dateCreated, userid, postid, title, categories, content)
 */
function info_simpleSearch($args) {
    global $wpdb;

	$r['s'] = $args[0];
	$r['offset'] = (int) $args[1];
	$r['posts_per_page'] = (int) $args[2];//pasar -1 si se quieren todos

    $full_post = 1;
	if (isset($args[3])) {
	  $full_post = $args[3];
	}

    $attachments = 0;
	if (isset($args[4])) {
	  $attachments = $args[4];
	}

	do_action('xmlrpc_call', 'info.simpleSearch');

	$get_posts = new WP_Query;
	$posts_list = $get_posts->query($r);

	if (!$posts_list) {
		$this_error = new IXR_Error(501, __('Either there are no posts with that text, or something went wrong.'));
		return($this_error);
	}

	foreach ($posts_list as $entry) {
		$post_date = mysql2date('Ymd\TH:i:s', $entry->post_date);

		$categories = array();
		$catids = wp_get_post_categories($entry->ID);
		foreach($catids as $catid) {
			$categories[] = get_cat_name($catid);
		}
		$link = post_permalink($entry->ID);

		if ($attachments) {
		  $ret_att = info_getAttachments($entry->ID);
	    }
		else {
		  $ret_att = NULL;
	    }

		if($full_post) {
		  $post = get_extended($entry->post_content);
          $content = wpautop($post['main'] .' '. $post['extended']);//para que salgan bien los cambios de linea en el cliente
	    }
		else {
		  $content = '';
	    }
		$posts_search[] = array(
			'dateCreated' => new IXR_Date($post_date),
			'userid' => $entry->post_author,
			'postid' => $entry->ID,
			'title' => $entry->post_title,
			'link' => $link,
			'att_links' => $ret_att,
			'content' => $content,
		);
	}

    $total = $get_posts->found_posts;
	return array('total' => $total, 'list' => $posts_search);
}

/* info.advancedSearch: advanced search of published posts
 * args[0] (string) text to search
 * args[1] (int) category name
 * args[2] (int) year
 * args[3] (int) month
 * args[4] (int) day
 * args[5] author's username
 * args[6] (int) start: the first post to return
 * args[7] (int) limit: the maximum number of posts to return
 * args[8] (int) full_post: 1 -> to return full posts (content), 0 -> to return post titles
 * args[9] (int) attachments: 1 to return attachment links, 0 dont return attachments (default)
 * return arreglo asociativo de:
 *    - total: total of published posts recovered
 *    - list: array of posts (dateCreated, userid, postid, title, categories, content)
 */
function info_advancedSearch($args) {
    global $wpdb;

	$r['s'] = $args[0];
	$r['category_name'] =  $args[1];
	$r['year'] = $args[2];
	$r['monthnum'] = $args[3];
	$r['day'] = $args[4];
	$r['author_name'] = $args[5];
	$r['offset'] = (int)$args[6];
	$r['posts_per_page'] = (int)$args[7];//pasar -1 si se quieren todos

    $full_post = 1;
	if (isset($args[8])) {
	  $full_post = $args[8];
	}

    $attachments = 0;
	if (isset($args[9])) {
	  $attachments = $args[9];
	}
	do_action('xmlrpc_call', 'info.advancedSearch');

	$get_posts = new WP_Query;
	$posts_list = $get_posts->query($r);

	if (!$posts_list) {
		$this_error = new IXR_Error(501, __('Either there are no posts with that text, or something went wrong.'));
		return($this_error);
	}
    $total = $get_posts->found_posts;

	foreach ($posts_list as $entry) {
		$post_date = mysql2date('Ymd\TH:i:s', $entry->post_date);

		$categories = array();
		$catids = wp_get_post_categories($entry->ID);
		foreach($catids as $catid) {
			$categories[] = get_cat_name($catid);
		}
		$link = post_permalink($entry->ID);

		if ($attachments) {
		  $ret_att = info_getAttachments($entry->ID);
	    }
		else {
		  $ret_att = NULL;
	    }

		if($full_post) {
		  $post = get_extended($entry->post_content);
          $content = wpautop($post['main'] .' '. $post['extended']);//para que salgan bien los cambios de linea en el cliente
	    }
		else {
		  $content = '';
	    }
		// Get the author info.
		$author = get_userdata($entry->post_author);

		$posts_search[] = array(
			'dateCreated' => new IXR_Date($post_date),
			'userid' => $entry->post_author,
			'postid' => $entry->ID,
			'title' => $entry->post_title,
			'link' => $link,
			'att_links' => $ret_att,
			'categories' => $categories,
			'content' => $content,
			'wp_author_display_name' => $author->display_name,
		);
	}

    $total = $get_posts->found_posts;
	return array('total' => $total, 'list' => $posts_search);
}

/* info.login_pass: check the combination of user_login and user_pass at blog
 * return true: if is ok or false and error
 */
function info_login_pass_ok($user_login, $user_pass) {
    global $this_error;

	/*if ( !get_option( 'enable_xmlrpc' ) ) {
		$this_error = new IXR_Error( 405, sprintf( __( 'XML-RPC services are disabled on this blog.  An admin user can enable them at %s'),  admin_url('options-writing.php') ) );
	    return false;
	}*/
	if (!user_pass_ok($user_login, $user_pass)) {
		$this_error = new IXR_Error(403, 'Bad login/pass combination.');
	    return false;
	}
	return true;
}

function infoxmlrpc_escape(&$array) {
	global $wpdb;

	if(!is_array($array)) {
		return($wpdb->escape($array));
	}
	else {
		foreach ( (array) $array as $k => $v ) {
			if (is_array($v)) {
				infoxmlrpc_escape($array[$k]);
			} else if (is_object($v)) {
				//skip
			} else {
				$array[$k] = $wpdb->escape($v);
			}
		}
	}
}

/* info.Attachments: to get post attachments by post id
 * id (int) : id of post that we want to recover attachments
 * return: array of
 *    - total: total of post attachments
 *    - list: array of attachments (id, title, link)
 */
function info_getAttachments($id) {
	global $wpdb;

	$att_links = array();
	$total_att = 0;

    $query = "SELECT ID, post_title, guid FROM $wpdb->posts WHERE post_type='attachment' AND post_parent=$id";
    $atts = $wpdb->get_results($query);

    foreach($atts as $att) {
	    $att_links[] = array(
		  'id' => $att->ID,
		  'title' => $att->post_title,
		  'link' => $att->guid,
	    );
	}
	$total_att = count($att_links);
    $ret_att = $total_att ? array('total' => $total_att, 'list' => $att_links) : NULL;
	return $ret_att;
}


?>