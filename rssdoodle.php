<?php
/*
Plugin Name: RSSdoodle
Plugin URI: http://www.lessnau.com/rssdoodle/
Description:  Allows a user to create posts and categories based on google news and videos keyword searches. Each post is a digest of posts in that category. By <a href="http://www.lessnau.com/">The Lessnau Lounge</a>.
Version: 2.0.1
*/

require_once(ABSPATH.WPINC.'/class-snoopy.php'); 

class RSSdoodle {
    var $snooper;
    var $keyword_table;
    var $posts_table;

    /**
     * Sets up object-level variables and hooks into WordPress.
     */
    function RSSdoodle() {
        global $wpdb;

        $this->keyword_table = $wpdb->prefix.'rssdoodle_keywords';
        $this->posts_table = $wpdb->prefix.'rssdoodle_posts';
        $this->snooper = new RSSdoodle_Feed();

        add_action('init', array(&$this, 'init'));
        add_action('activate_rssdoodle/rssdoodle.php', array(&$this, 'install'));
    	add_filter('cron_schedules', array(&$this, 'cron_schedules'));
    	add_action('rssdoodle_ping', array(&$this, 'ping'));
    	add_action('rssdoodle_post', array(&$this, 'post'));

        // Public stuff
    	add_action('pre_get_posts', array(&$this,'pre_get_posts'));
    	add_action('wp_head', array(&$this, 'styles'));
    }
    
    /**
     * Adds styles to the theme.
     */
    function styles() {
        echo apply_filters('rssdoodle_stylelink', '<link rel="stylesheet" href="'.trailingslashit(get_option('siteurl')).PLUGINDIR.'/rssdoodle/rssdoodle.css'.'" type="text/css" />');
    }
    
    /**
     * Determines which categories to include on the front page.
     */
    function pre_get_posts() {
        if( is_home() ) {
            add_filter('posts_where', array(&$this, 'no_fp_posts_where'));
            add_filter('posts_join', array(&$this, 'no_fp_posts_join'));
        } else if( is_feed() && $this->get_option('show_in_rss')=='N') {
            add_filter('posts_where', array(&$this, 'no_feeds_posts_where'));
            add_filter('posts_join', array(&$this, 'no_feeds_posts_join'));
        }
    }
    
    /**
     * Determines which categories to include on the front page.
     */
    function no_fp_posts_where($where) {
        global $wpdb;
        $where .= " AND (rssdoodle_meta.meta_key IS NULL OR rssdoodle_meta.meta_key != '_rssdoodle_fp_exclude')";
        return $where;
    }

    /**
     * Determines which categories to include on the front page.
     */
    function no_fp_posts_join($join) {
        global $wpdb;
        $join .= " LEFT JOIN $wpdb->postmeta AS rssdoodle_meta";
		$join .= " ON $wpdb->posts.ID = rssdoodle_meta.post_id";
		$join .= " AND rssdoodle_meta.meta_key='_rssdoodle_fp_exclude'";
		return $join;
    }
    
    /**
     * Determines which categories to include on the front page.
     */
    function no_feeds_posts_where($where) {
        global $wpdb;
        $where .= " AND (rssdoodle_meta.meta_key IS NULL OR rssdoodle_meta.meta_key != '_rssdoodle_post')";
        return $where;
    }

    /**
     * Determines which categories to include on the front page.
     */
    function no_feeds_posts_join($join) {
        global $wpdb;
        $join .= " LEFT JOIN $wpdb->postmeta AS rssdoodle_meta";
		$join .= " ON $wpdb->posts.ID = rssdoodle_meta.post_id";
		$join .= " AND rssdoodle_meta.meta_key='_rssdoodle_post'";
		return $join;
    }

    /**
     * Posts the time-based tweets for a keyword.  Called from cron.
     */
    function post($keyword_id) {
        global $wpdb;

        kses_remove_filters(); // Lets us use div's and such.
        $this->update_posts($keyword_id);
        $query = "SELECT * FROM {$this->keyword_table} WHERE id=$keyword_id";
        $kw = $wpdb->get_row($query);
        $this->post_keyword($keyword_id,$kw);
		$results1 = print_r($kw,true);
    }
	
   
    
    /**
     * Updates and posts the tweet-based keywords.  Called from cron.
     */
    function ping() {
        $this->update_posts();
        $this->post_count_posts();
    }
   
    
    /**
     * Installs the tables/options necessary if they're not there.
     */
    function install() {
        global $wpdb;
        
        if( $wpdb->get_var( "SHOW TABLES LIKE '{$this->keyword_table}'" ) != $this->keyword_table ) {
            $sql1 = <<<EOQ
            CREATE TABLE `{$this->posts_table}` (
              `id` bigint(20) NOT NULL auto_increment,
              `blog_name` varchar(255) NOT NULL,
              `blog_url` varchar(255) NOT NULL,
              `post_title` varchar(255) NOT NULL,
              `post_excerpt` text NOT NULL,
              `post_date` datetime NOT NULL default '0000-00-00 00:00:00',
              `permalink` varchar(255) NOT NULL,
              `keyword_id` bigint(20) NOT NULL default '0',
			  `post_type` varchar(50) NOT NULL default 'technorati',
              `posted` enum('Y','N') NOT NULL default 'N',
              PRIMARY KEY  (`id`),
              UNIQUE KEY `permalink` (`permalink`)
            )
EOQ;

            $sql2 = <<<EOQ
            CREATE TABLE `{$this->keyword_table}` (
              `id` bigint(20) NOT NULL auto_increment,
              `keyword` varchar(255) NOT NULL default '',
              `search_string` text NOT NULL,
              `last_searched` datetime default '0000-00-00 00:00:00',
              `update_interval` varchar(20) NOT NULL default '',
              `front_page` char(1) default 'Y',
              `do_pings` char(1) default 'N',
              `category_id` bigint(20) default NULL,
			   `tag_id` varchar(255) default NULL,
			  `keyword_type` varchar(50) NOT NULL default 'technorati',
              `user_id` bigint(20) default NULL,
			  `last_count` bigint(20) default NULL,
              PRIMARY KEY  (`id`),
              UNIQUE KEY `keyword` (`keyword`,`keyword_type`)
            )
EOQ;

    		require_once( ABSPATH . 'wp-admin/upgrade-functions.php' );
    		dbDelta( $sql1 );
    		dbDelta( $sql2 );
            $options = array('comment_order'       => 'DESC',
                             'links_nofollow'      => True,
                             'links_newwindow'     => True,
                             'max_posts_per_post'  => 50,
                             'do_pings_default'    => 'N',
                             'last_error'          => '',
                             'technorati_key'      => '',
                             'blocked'             => array(),
							 'donate_link'      => 'https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&amp;business=john@linkadage.com&amp;currency_code=&amp;amount=&amp;return=&amp;item_name=WordPress+Plugin+Development+Donation'
                             );
            add_option('rssdoodle', $options);
            
            wp_schedule_event(time(), 'every3hours', 'rssdoodle_ping');
			
    	}else if( $wpdb->get_var( "SHOW TABLES LIKE '{$this->keyword_table}'" ) == $this->keyword_table ) {
		
		$query = 'SHOW COLUMNS FROM  '.$this->keyword_table.' ';
		$column = $wpdb->get_results($query);
		 foreach( $column as $cl ) {
				$columns[] = $cl->Field;
		 }
			 
		if (!in_array('tag_id', $columns) || !in_array('last_count', $columns)) {
			if (!in_array('tag_id', $columns)){
				$sql1 = "ALTER TABLE `{$this->keyword_table}` ADD `tag_id` VARCHAR( 255 ) NULL AFTER `category_id` , ADD `keyword_type` VARCHAR( 50 ) NOT NULL DEFAULT 'technorati' AFTER `tag_id` , ADD `last_count` bigint( 20 ) NOT NULL DEFAULT '0' AFTER `keyword_type` , CHANGE `search_string` `search_string` TEXT CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL DEFAULT NULL ,  DROP INDEX `keyword` , ADD UNIQUE `keyword` ( `keyword` , `keyword_type` )";
				$sql2 = "ALTER TABLE `{$this->posts_table}` ADD `post_type` VARCHAR( 50 ) NOT NULL DEFAULT 'technorati' AFTER `keyword_id`";
					require_once( ABSPATH . 'wp-admin/upgrade-functions.php' );
					$wpdb->query($sql1);
					$wpdb->query($sql2);
			}else if (!in_array('last_count', $columns)){
					$sql1 = "ALTER TABLE `{$this->keyword_table}`  ADD `last_count` bigint( 20 ) NOT NULL DEFAULT '0' AFTER `keyword_type` ";
						require_once( ABSPATH . 'wp-admin/upgrade-functions.php' );
						$wpdb->query($sql1);
			}
		
					$old_options = get_option('rssdoodle');
					$new_options = array('comment_order'       => 'DESC',
									 'links_nofollow'      => True,
									 'links_newwindow'     => True,
									 'max_posts_per_post'  => 10,
									 'do_pings_default'    => 'N',
									 'last_error'          => '',
									 'technorati_key'      => '',
									 'blocked'             => array(),
									 'donate_link'      => 'https://www.paypal.com/cgi-bin/webscr?cmd=_xclick&amp;business=john@linkadage.com&amp;currency_code=&amp;amount=&amp;return=&amp;item_name=WordPress+Plugin+Development+Donation'
									 );
					$options = array_merge($old_options, $new_options);
					update_option('rssdoodle', $options);
					wp_schedule_event(time(), 'every3hours', 'rssdoodle_ping');
			}		
		}
    }
    
    /**
     * Adds a couple extra schedules to the cron system.  'every3days' is used for updating tweets.
     */
    function cron_schedules($scheds) {
        $extra_scheds = array('3days'=>array('interval'=>259200, 'display'=>__('Every 3 Days', 'rssdoodle')),
                              'every3hours' => array('interval'=>10800, 'display'=>__('Every 3 Hours', 'rssdoodle')));
        return array_merge($extra_scheds, $scheds);
    }

    /**
     * Makes sure the plugin is installed.  Loads translations, sets up menu.  Handles POSTs.
     */
    function init() {
        $this->install();
    	load_plugin_textdomain('rssdoodle', PLUGINDIR.'/rssdoodle');
    	
    	add_action('admin_menu', array(&$this, 'admin_menu'));
    	$this->handle_posts();
    }

    /**
     * Handles any POSTs made from the plugin.
     */
    function handle_posts() {
        if( isset($_POST['rssdoodle-new_keyword'] ) ) {
            check_admin_referer("new-keyword");
            $keyword       = stripslashes($_POST['keyword']);
            $search_string = stripslashes($_POST['search_string']);
            $category      = $_POST['cat'];
            $interval      = $_POST['update_interval'];
            $front_page    = $_POST['front_page'];
            $do_pings      = $_POST['do_pings'];
			$type    	   = empty($_POST['type']) ? 'technorati':$_POST['type'];
			$tag 		= stripslashes($_POST['tag']);
			if($type=='multiblog'){
				$_POST['search_string']=array_unique($_POST['search_string']);
				$search_string=implode(',',$_POST['search_string']);
				$search_string = str_replace(',,', ',', $search_string);

			}
            $this->add_keyword( $keyword, $search_string, $category, 
                                $interval, $front_page, $do_pings ,$type , $tag );
			if($type=='news')
      	      	wp_redirect("admin.php?page=rssdoodle_newsfeed_options&rssdoodle_message=1&keyword_name=".urlencode(htmlentities($keyword, ENT_QUOTES)));
			elseif($type=='video')
      	      	wp_redirect("admin.php?page=rssdoodle_videofeed_options&rssdoodle_message=1&keyword_name=".urlencode(htmlentities($keyword, ENT_QUOTES)));
			elseif($type=='multiblog')
      	      	wp_redirect("admin.php?page=rssdoodle_multifeed_options&rssdoodle_message=1&multifeed_name=".urlencode(htmlentities($keyword, ENT_QUOTES)));
			else
            	wp_redirect("admin.php?page=rssdoodle_options&rssdoodle_message=1&keyword_name=".urlencode(htmlentities($keyword, ENT_QUOTES)));

        } else if( isset($_POST['rssdoodle-edit_keyword'] ) ) {
		
			
            $id = $_POST['id'];
            check_admin_referer("edit-keyword_$id");
            $keyword       = stripslashes($_POST['keyword']);
            $search_string = stripslashes($_POST['search_string']);
            $interval      = $_POST['update_interval'];
            $front_page    = $_POST['front_page'];
            $category_id   = $_POST['cat'];
            $do_pings      = $_POST['do_pings'];
			$type    	   = empty($_POST['type']) ? 'technorati':$_POST['type'];
			$tag 		= stripslashes($_POST['tag']);
			
			if($type=='multiblog'){
				$_POST['search_string']=array_unique($_POST['search_string']);
				$search_string=implode(',',$_POST['search_string']);
				$search_string = str_replace(',,', ',', $search_string);

			}		
				
            $this->edit_keyword( $id, $keyword, $search_string, 
                                 $interval, $front_page, $category_id, $do_pings, $type , $tag );
			if($type=='news')
				wp_redirect("admin.php?page=rssdoodle_newsfeed_options&rssdoodle_message=2&keyword_name=".urlencode(htmlentities($keyword, ENT_QUOTES)));
			elseif($type=='video')	
				wp_redirect("admin.php?page=rssdoodle_videofeed_options&rssdoodle_message=2&keyword_name=".urlencode(htmlentities($keyword, ENT_QUOTES)));
			elseif($type=='multiblog')	
				wp_redirect("admin.php?page=rssdoodle_multifeed_options&rssdoodle_message=2&multifeed_name=".urlencode(htmlentities($keyword, ENT_QUOTES)));
			else					 
           	 wp_redirect("admin.php?page=rssdoodle_options&rssdoodle_message=2&keyword_name=".urlencode(htmlentities($keyword, ENT_QUOTES)));

        } else if( isset($_GET['rssdoodle-delete_keyword'])) {
			global $wpdb;
            $id = $_GET['rssdoodle-delete_keyword'];
            $name = stripslashes($_GET['keyword_name']);
            check_admin_referer("delete-keyword_$id");
			
			$keyword_query = "SELECT keyword_type FROM {$this->keyword_table} WHERE id=$id ";
			$keywords = $wpdb->get_row($keyword_query);
			$type=$keywords->keyword_type;

            $this->delete_keyword($id);
			
					
			if(trim($type)=='news')
				wp_redirect("admin.php?page=rssdoodle_newsfeed_options&rssdoodle_message=3&keyword_name=".urlencode(htmlentities($name, ENT_QUOTES)));
			else if(trim($type)=='video')
				wp_redirect("admin.php?page=rssdoodle_videofeed_options&rssdoodle_message=3&keyword_name=".urlencode(htmlentities($name, ENT_QUOTES)));
			elseif($type=='multiblog')
				wp_redirect("admin.php?page=rssdoodle_multifeed_options&rssdoodle_message=3&multifeed_name=".urlencode(htmlentities($name, ENT_QUOTES)));
			else
            	wp_redirect("admin.php?page=rssdoodle_options&rssdoodle_message=3&keyword_name=".urlencode(htmlentities($name, ENT_QUOTES)));

        } else if( isset($_POST['rssdoodle-update_options'] ) ) {
            check_admin_referer('update-options');
            
            $nofollow_links = $_POST['nofollow_links']=='nofollow';
            $newwindow_links = $_POST['newwindow_links']=='newwindow';
            $tweetorder = in_array($_POST['postorder'], array('ASC', 'DESC')) ? $_POST['postorder'] : 'ASC';
            $maxposts = in_array($_POST['maxposts'], array('0','10','20','50','100')) ? $_POST['maxposts'] : '0';
			$blocked = explode(',', $_POST['blocked']);
            $do_pings_default = empty($_POST['do_pings_default']) ? 'N':'Y';
            $show_in_rss = empty($_POST['show_in_rss'])?'N':'Y';

            $old_options = get_option('rssdoodle');
            $new_options = array('comment_order'       => $tweetorder,
                                 'links_nofollow'      => $nofollow_links,
                                 'links_newwindow'     => $newwindow_links,
                                 'max_posts_per_post'  => $maxposts,
								 'blocked'             => $blocked,
								 'do_pings_default'    => $do_pings_default,
                                 'show_in_rss'         => $show_in_rss,
                                 );
            if( !defined('RSSDOODLE_TECHNORATI_API_KEY')) {
                $new_options['technorati_key'] = $_POST['technorati_key'];
            }

            $options = array_merge($old_options, $new_options);
            update_option('rssdoodle', $options);
            
            wp_redirect("options-general.php?page=rssdoodle_general_options&rssdoodle_message=4");
        } 
		
    }
    
    /**
     * Updates a keyword with new settings.
     */
    function edit_keyword($id, $keyword, $search_string, 
                          $interval, $front_page, $category_id, $do_pings, $type , $tag) {
        global $wpdb;
        $id = (int)$id;
		
		if( trim($category_id) == 'new' ) {
				if(trim($type) == 'video' )
					$category_id = get_cat_ID("Videos about {$keyword}");
				else if(trim($type) == 'news' )
					$category_id = get_cat_ID("News about {$keyword}");
				else if(trim($type) == 'multiblog' )
					$category_id = get_cat_ID("Posts about {$keyword}");
				else
					$category_id = get_cat_ID("Articles about {$keyword}");
					
            if( $category_id == 0 ) {
                require_once(ABSPATH.'/wp-admin/includes/taxonomy.php');
				if(trim($type) == 'video' )
					$category_id = wp_create_category("Videos about {$keyword}");
				else if(trim($type) == 'news' )
					$category_id = wp_create_category("News about {$keyword}");
				else if(trim($type) == 'multiblog' )
					$category_id = wp_create_category("Posts about {$keyword}");
				else
                	$category_id = wp_create_category("Articles about {$keyword}");   
            }
        }
		
		 $query = "UPDATE {$this->keyword_table} SET keyword=%s, search_string=%s, update_interval=%s, front_page=%s, category_id=%d, tag_id=%s, do_pings=%s  , keyword_type=%s WHERE id=%d";
        $query = $wpdb->prepare($query, $keyword, $search_string, $interval, empty($front_page) ? 'N':'Y', $category_id, $tag, empty($do_pings) ? 'N':'Y', $type, $id);
        $wpdb->query($query);

        wp_clear_scheduled_hook('rssdoodle_post', $id);
        if( strpos($interval, 'posts') === False ) {
            // If it's a time-based keyword, then schedule the updates
            wp_schedule_event(time(), $interval, 'rssdoodle_post', array($id));
        } else {
            // Otherwise, just do a single update
            wp_schedule_single_event(time(), 'rssdoodle_ping');
        }
    }
    
    /**
     * Deletes an existing keyword.
     */
    function delete_keyword($id) {
        global $wpdb;
        
        $query = "DELETE FROM {$this->posts_table} WHERE keyword_id=$id ";
        $wpdb->query($query);

        $query = "DELETE FROM {$this->keyword_table} WHERE id=$id";
        $wpdb->query($query);
     
        wp_clear_scheduled_hook('rssdoodle_post', (int)$id);   
    }
    
    /**
     * Adds a new keyword.
     */
    function add_keyword($keyword, $search_string, 
                         $category, $update_interval, 
                         $front_page, $do_pings, $type, $tag) {
        global $wpdb, $user_ID;

        // Create a new category if needed
        if( $category == 'new' ) {
		
				if(trim($type) == 'video' )
					$category = get_cat_ID("Videos about {$keyword}");
				else if(trim($type) == 'news' )
					$category = get_cat_ID("News about {$keyword}");
				else if(trim($type) == 'multiblog' )
					$category = get_cat_ID("Posts about {$keyword}");
				else
					$category = get_cat_ID("Articles about {$keyword}");

            if( $category == 0 ) {
                require_once(ABSPATH.'/wp-admin/includes/taxonomy.php');
				if(trim($type) == 'video' )
					$category = wp_create_category("Videos about {$keyword}");
				else if(trim($type) == 'news' )
					$category = wp_create_category("News about {$keyword}");
				else if(trim($type) == 'multiblog' )
					$category = wp_create_category("Posts about {$keyword}");
				else
                	$category = wp_create_category("Articles about {$keyword}");   
            }
        }

        $query = "INSERT IGNORE INTO {$this->keyword_table} (keyword,search_string,update_interval,front_page,category_id,tag_id,user_id,do_pings,keyword_type) VALUES (%s,%s,%s,%s,%d,%s,%d,%s,%s)";
        $query = $wpdb->prepare($query, $keyword, $search_string, $update_interval, empty($front_page) ? 'N':'Y', $category, $tag, $user_ID, empty($do_pings) ? 'N':'Y',$type);

        $wpdb->query($query);
		$keyid=$wpdb->insert_id;
        $this->update_posts($wpdb->insert_id);
        
        if( strpos($update_interval, 'posts') === False ) {
            // If it's a time-based keyword, then schedule the updates
            wp_clear_scheduled_hook('rssdoodle_post', $keyid);
            wp_schedule_event(time(), $update_interval, 'rssdoodle_post', array($keyid));
        } else {
            // Otherwise, just do a single update
            // wp_schedule_single_event(time(), 'rssdoodle_ping');
            $this->post_count_posts();
        }
    }
	
    /**
     * Add a link to the plugin to the menu.
     */
    function admin_menu() {
	  
		$page =	add_object_page('RSSdoodle', 'RSSdoodle', 'manage_options', 'rssdoodle_general_options',  array(&$this, 'genereal_options'));
		$page = add_submenu_page('rssdoodle_general_options', 'RSSdoodle > General Options', 'RSSdoodle General Options', 'manage_options', 'rssdoodle_general_options',  array(&$this, 'genereal_options'));
		$page = add_submenu_page('rssdoodle_general_options', 'RSSdoodle > Manage  Multiple Blog Feed', 'RSSdoodle Multiple Blog Feed', 'manage_options', 'rssdoodle_multifeed_options',  array(&$this, 'multifeed_options_page'));
		$page = add_submenu_page('rssdoodle_general_options', 'RSSdoodle > Manage News Feed', 'RSSdoodle News Feed', 'manage_options', 'rssdoodle_newsfeed_options', array(&$this, 'newsfeed_options_page'));
		$page = add_submenu_page('rssdoodle_general_options', 'RSSdoodle > Manage Video Feed', 'RSSdoodle Video Feed', 'manage_options', 'rssdoodle_videofeed_options',  array(&$this, 'videofeed_options_page'));

	
	}
	
	/**
	 * Do the options page.
	 */
    function options_page() {
        global $wpdb;

        $options = get_option('rssdoodle');
       
        $keyword_query = "SELECT * FROM {$this->keyword_table} where keyword_type = 'technorati'  ";
		
        $keywords = $wpdb->get_results($keyword_query);

		if( empty($keywords) ) {
            echo '<div class="error"><p>';
            _e('There is no keywords to search hwith technorati RSS feed.', 'rssdoodle');
            echo '</p></div>';
        }
		        // Show a message if needed
        if( isset($_GET['rssdoodle_message']) ) {
            $messages = array( '1' => __("Successfully added the keyword search <b>%s</b>", 'rssdoodle'),
                               '2' => __("Successfully edited the keyword search <b>%s</b>", 'rssdoodle'),
                               '3' => __("Successfully deleted the keyword search <b>%s</b>", 'rssdoodle'),
                               '4' => __("Successfully updated the options", 'rssdoodle'));
            $keyword = @stripslashes($_GET['keyword_name']);
            $msg = sprintf($messages[$_GET['rssdoodle_message']], $keyword);

            echo "<div id=\"message\" class=\"updated fade\"><p>$msg</p></div>";
        }

        ?>
        <div class="wrap">
        <h2><?php _e("Keyword Searches", "rssdoodle"); ?></h2>
        <p></p>
        <table class="widefat">
        <thead>
            <tr>
                <th><?php _e('Post Keyword Name', 'rssdoodle'); ?></th>
                <th><?php _e('Keyword or Keyword String', 'rssdoodle'); ?></th>
                <th><?php _e('Category', 'rssdoodle'); ?></th>
				<th><?php _e('Tags', 'rssdoodle'); ?></th>
                <th><?php _e('Interval', 'rssdoodle'); ?></th>
                <th><?php _e('Front Page', 'rssdoodle'); ?></th>
                <th colspan="2"><?php _e('Actions', 'rssdoodle'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php
        if( empty($keywords) ) {
            ?>
            <tr colspan="5"><td><?php _e('You currently have no keyword searches.  Add one below!', 'rssdoodle') ?></td></tr>
            <?php
        } else {
            $class = "";
            foreach( $keywords as $kw ) {
                echo "<tr id=\"search-{$kw->id}\" class=\"$class\">";
                echo "<td>". $kw->keyword."</td>\n";
                echo "<td>". $kw->search_string."</td>\n";
                echo "<td>".get_cat_name($kw->category_id)."</td>\n";
				echo "<td>". $kw->tag_id ."</td>\n";
                echo "<td>{$kw->update_interval}</td>\n";
                echo "<td>". ($kw->front_page=='Y' ? 'Yes' : 'No') ."</td>\n";
                echo "<td><a class='view' href='admin.php?page=rssdoodle_options&amp;rssdoodle_edit={$kw->id}#rssdoodle_edit'>Edit</a></td>\n";
                echo "<td><a class='delete' href='".wp_nonce_url("admin.php?page=rssdoodle_options&amp;rssdoodle-delete_keyword={$kw->id}&amp;keyword_name={$kw->keyword}", 'delete-keyword_' . $kw->id)."' onclick=\"return confirm('" . js_escape(sprintf( __("You are about to delete the keyword '%s'.\n'OK' to delete, 'Cancel' to stop.", 'rssdoodle'), $kw->keyword)) . "' );\">Delete</a></td>\n";
                echo "</tr>";
                $class = empty($class)?"alternate":"";
            }
        }        
        ?>
        </tbody>
        </table>
        </div>
        <div class="wrap narrow" id="rssdoodle_edit">
        <?php
            if( isset($_GET['rssdoodle_edit']) ) {
                $id = $_GET['rssdoodle_edit'];

                $query = "SELECT * FROM {$this->keyword_table} WHERE id=$id";
                $keyword = $wpdb->get_row($query, ARRAY_A);

                $header = __(sprintf('Edit keyword search: %s', $keyword['keyword']), 'rssdoodle');
                $header_text = __('Add the keywords or key word phrases you want your posts to contain.', 'rssdoodle');
                $button_text = __('Edit Keyword Search &raquo;', 'rssdoodle');
                $nonce_name = "edit-keyword_$id";
                $action_name = "rssdoodle-edit_keyword";
                $after = "<input type=\"hidden\" name=\"id\" value=\"{$keyword['id']}\" />\n";
            } else {
                $header = __('Add new keyword search', 'rssdoodle');
                $header_text = __('Add the keywords or key word phrases you want your posts to contain.', 'rssdoodle');
                $button_text = __('Add Keyword Search &raquo;', 'rssdoodle');
                $nonce_name = "new-keyword";
                $action_name = "rssdoodle-new_keyword";
                $after = '';
                
                $keyword = array( 'keyword'=>'', 'update_interval'=>'', 'front_page'=>'Y', 
                                  'category_id'=>$this->get_option('default_category'), 
                                  'do_pings'=>$this->get_option('do_pings_default'));
            }
            add_filter('wp_dropdown_cats', array(&$this, 'new_cat_option'));
        ?>
            <h2><?php echo $header ?></h2>
            <p><?php echo $header_text ?></p>
            <form method="post" action="admin.php?page=rssdoodle_options">
                <table width="100%" cellspacing="2" cellpadding="5" class="editform form-table"><tbody>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="keyword"><?php _e('Post Keyword Name', 'rssdoodle'); ?>:</label><br /><span style="font-size:xx-small">Post title will be "Posts about <em>Keyword</em>"</span></th>
            			<td width="67%"><input type="text" size="40" value="<?php echo htmlentities($keyword['keyword']) ?>" id="keyword" name="keyword"/></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="search_string"><?php _e('Keyword or Keyword String', 'rssdoodle'); ?>:</label><br /><span style="font-size:xx-small">e.g. "Barack Obama", "Obama OR Clinton", "Obama AND baseball"</span></th>
            			<td width="67%"><input type="text" size="40" value="<?php echo htmlentities($keyword['search_string']) ?>" id="search_string" name="search_string"/></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="keyword"><?php _e('Category', 'rssdoodle'); ?>:</label></th>
            			<td width="67%"><?php wp_dropdown_categories("hide_empty=0&selected={$keyword['category_id']}") ?></td>
            		</tr>
<tr>
            			<th width="33%" valign="top" scope="row"><label for="keyword"><?php _e('Tags', 'rssdoodle'); ?>:</label><br /><span style="font-size:xx-small">Enter the tags with comma separated text e.g. "RSSdoodle, Keyword, Tag "</span></th>
            			<td width="67%" >	
						<input type="text" size="40" value="<?php echo htmlentities($keyword['tag_id']) ?>" id="tag" name="tag"/>
						
					</td>
            		</tr>            		
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="update_interval"><?php _e('Interval', 'rssdoodle'); ?>:</label></th>
            			<td width="67%"><?php $this->interval_dropdown($keyword['update_interval']) ?></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="front_page"><?php _e('Front Page', 'rssdoodle'); ?>:</label></th>
            			<td width="67%"><input type="checkbox" <?php checked($keyword['front_page'], 'Y') ?> id="front_page" name="front_page"/></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="do_pings"><?php _e('Do Pings', 'rssdoodle'); ?>:</label></th>
            			<td width="67%"><input type="checkbox" <?php checked($keyword['do_pings'], 'Y') ?> id="do_pings" name="do_pings"/></td>
            		</tr>
            	</tbody></table>
                <p class="submit"><input type="submit" value="<?php echo $button_text ?>" name="<?php echo $action_name ?>"/></p>
                <?php wp_nonce_field($nonce_name) ?>
                <?php echo $after ?>
            </form>

        </div>
        <?php
    }
	
function genereal_options(){
        global $wpdb;

        $options = get_option('rssdoodle');
        
        
        // Show a message if needed
        if( isset($_GET['rssdoodle_message']) ) {
            $messages = array( '1' => __("Successfully added the keyword search <b>%s</b>", 'rssdoodle'),
                               '2' => __("Successfully edited the keyword search <b>%s</b>", 'rssdoodle'),
                               '3' => __("Successfully deleted the keyword search <b>%s</b>", 'rssdoodle'),
                               '4' => __("Successfully updated the options", 'rssdoodle'));
            $keyword = @stripslashes($_GET['keyword_name']);
            $msg = sprintf($messages[$_GET['rssdoodle_message']], $keyword);

            echo "<div id=\"message\" class=\"updated fade\"><p>$msg</p></div>";
        }

        ?>
        <div class="wrap narrow" id="rssdoodle_edit">
            
            <h2><?php _e('General Options', 'rssdoodle') ?> <span style="font-size:small">(These options will be applied to all RSSdoodle keyword posts)</span></h2>
            <form method="post" action="options-general.php?page=rssdoodle_general_options">
                <table width="100%" cellspacing="2" cellpadding="5" class="editform form-table"><tbody>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="nofollow_links"><?php _e('Nofollow links', 'rssdoodle'); ?>:</label></th>
            			<td width="67%">
            			    <select id="nofollow_links" name="nofollow_links">
            			        <option value="follow" <?php selected($options['links_nofollow'], false) ?>>Follow</option>
            			        <option value="nofollow" <?php selected($options['links_nofollow'], true) ?>>No-follow</option>
            			    </select>
            			</td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="newwindow_links"><?php _e('Links in new windows', 'rssdoodle'); ?>:</label></th>
            			<td width="67%">
            			    <select id="newwindow_links" name="newwindow_links">
            			        <option value="newwindow" <?php selected($options['links_newwindow'], true) ?>>New Window</option>
            			        <option value="samewindow" <?php selected($options['links_newwindow'], false) ?>>Same Window</option>
            			    </select>
            			</td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="tweetorder"><?php _e('Post order', 'rssdoodle'); ?>:</label></th>
            			<td width="67%">
            			    <select id="postorder" name="postorder">
            			        <option value="ASC" <?php selected($options['comment_order'], 'ASC') ?>>Ascending</option>
            			        <option value="DESC" <?php selected($options['comment_order'], 'DESC') ?>>Descending</option>
            			    </select>
            			</td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="maxposts"><?php _e('Max posts per post', 'rssdoodle'); ?>:</label></th>
            			<td width="67%">
            			    <select id="maxposts" name="maxposts">
            			        <option value="10" <?php selected($options['max_posts_per_post'], 10) ?>>10</option>
            			        <option value="20" <?php selected($options['max_posts_per_post'], 20) ?>>20</option>
            			        <option value="50" <?php selected($options['max_posts_per_post'], 50) ?>>50</option>
            			        <option value="100" <?php selected($options['max_posts_per_post'], 100) ?>>100</option>
            			        <option value="0" <?php selected($options['max_posts_per_post'], 0) ?>>All Available</option>
            			    </select>
            			</td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="do_pings_default"><?php _e('Do Pings by Default', 'rssdoodle'); ?>:</label></th>
            			<td width="67%"><input type="checkbox" <?php checked($options['do_pings_default'], 'Y') ?> id="do_pings_default" name="do_pings_default"/></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><a id="blockedhosts" name="blockedhosts"></a><label for="blocked"><?php _e('Blocked Website RSS Feeds', 'rssdoodle'); ?>:</label><br /><span style="font-size:xx-small">Comma-separated hostnames (e.g., example.blogger.com)<br />this will block auto RSS feeds from any domains you list</span></th>
            			<td width="67%"><input type="text" size="40" value="<?php echo implode(',',$this->get_option('blocked')) ?>" id="blocked" name="blocked"/></td>
            		</tr>
            		
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="show_in_rss"><?php _e('Show in RSS Feed', 'rssdoodle'); ?>:</label><br /><span style="font-size:xx-small"></span></th>
            			<td width="67%"><input type="checkbox" <?php checked($options['show_in_rss'], 'Y') ?> id="show_in_rss" name="show_in_rss"/></td>
            		</tr>
            	</tbody></table>
                <p class="submit"><input type="submit" value="<?php _e('Update Options', 'rssdoodle') ?>" name="rssdoodle-update_options"/></p>
                <?php wp_nonce_field('update-options') ?>
            </form>
			<p class="submit"> If this plugin helped you, you can contribute towards plugin development by <a title="Opens in New Window" target="_blank" href="<? echo $options['donate_link'] ?>"> Donating </a> to us  <a title="Opens in New Window" target="_blank" href="<? echo $options['donate_link'] ?>"> <img src="<?php echo get_option("home").'/'.PLUGINDIR;?>/rssdoodle/btn_donate_SM.gif" border="0"></a>.
        </p> 
        </div>
        <?php
    
		}	
		
    /**
	 * Do the multi feed options page.
	 */
	 
	 function multifeed_options_page() {
        global $wpdb;

        $options = get_option('rssdoodle');
		
		$keyword_query = "SELECT * FROM {$this->keyword_table} where keyword_type='multiblog'";
        $keywords = $wpdb->get_results($keyword_query);
		
		if( empty($keywords) ) {
            echo '<div class="error"><p>';
            _e('There is no feed urls.', 'rssdoodle');
            echo '</p></div>';
        }
		
        // Show a message if needed
        if( isset($_GET['rssdoodle_message']) ) {
            $messages = array( '1' => __("Successfully added the blog feed <b>%s</b>", 'rssdoodle'),
                               '2' => __("Successfully edited the blog feed <b>%s</b>", 'rssdoodle'),
                               '3' => __("Successfully deleted the blog feed <b>%s</b>", 'rssdoodle'),
                               '4' => __("Successfully updated the options", 'rssdoodle'));
            $keyword = @stripslashes($_GET['keyword_name']);
            $msg = sprintf($messages[$_GET['rssdoodle_message']], $keyword);

            echo "<div id=\"message\" class=\"updated fade\"><p>$msg</p></div>";
        }
		
        ?>
		
		<div class="wrap">
        <h2><?php _e("Mashup of feeds from specific blogs", "rssdoodle"); ?></h2>
        <p></p>
        <table class="widefat">
        <thead>
            <tr>
			
                <th><?php _e('Blog Feed', 'rssdoodle'); ?></th>
                <th><?php _e('Blog Feed URL', 'rssdoodle'); ?></th>
                <th><?php _e('Category', 'rssdoodle'); ?></th>
				<th><?php _e('Tags', 'rssdoodle'); ?></th>
                <th><?php _e('Interval', 'rssdoodle'); ?></th>
                <th><?php _e('Front Page', 'rssdoodle'); ?></th>
                <th colspan="2"><?php _e('Actions', 'rssdoodle'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php
        if( empty($keywords) ) {
            ?>
            <tr colspan="5"><td><?php _e('You currently have no keyword searches.  Add one below!', 'rssdoodle') ?></td></tr>
            <?php
        } else {
            $class = "";
            foreach( $keywords as $kw ) {
			
                echo "<tr id=\"search-{$kw->id}\" class=\"$class\">";
                echo "<td>". $kw->keyword."</td>\n";
                echo "<td>". $kw->search_string."</td>\n";
                echo "<td>".get_cat_name($kw->category_id)."</td>\n";
				echo "<td>". $kw->tag_id ."</td>\n";
                echo "<td>{$kw->update_interval}</td>\n";
                echo "<td>". ($kw->front_page=='Y' ? 'Yes' : 'No') ."</td>\n";
                echo "<td><a class='view' href='admin.php?page=rssdoodle_multifeed_options&amp;rssdoodle_edit={$kw->id}#rssdoodle_edit'>Edit</a></td>\n";
                echo "<td><a class='delete' href='".wp_nonce_url("admin.php?page=rssdoodle_multifeed_options&amp;rssdoodle-delete_keyword={$kw->id}&amp;keyword_name={$kw->keyword}", 'delete-keyword_' . $kw->id)."' onclick=\"return confirm('" . js_escape(sprintf( __("You are about to delete the keyword '%s'.\n'OK' to delete, 'Cancel' to stop.", 'rssdoodle'), $kw->keyword)) . "' );\">Delete</a></td>\n";
                echo "</tr>";
                $class = empty($class)?"alternate":"";
            
            }
        }        
        ?>
        </tbody>
        </table>
        </div>
		
		<div class="wrap narrow" id="rssdoodle_multifeed_edit">
        <?php
            			
			 if( isset($_GET['rssdoodle_edit']) ) {
                $id = $_GET['rssdoodle_edit'];

                $query = "SELECT * FROM {$this->keyword_table} WHERE id=$id";
                $keyword = $wpdb->get_row($query, ARRAY_A);

                $header = __(sprintf('Edit blog RSS feed: %s', $keyword['keyword']), 'rssdoodle');
                $header_text = __('Add the blog RSS feed you want your posts to contain.', 'rssdoodle');
                $button_text = __('Edit blog RSS feed url &raquo;', 'rssdoodle');
                $nonce_name = "edit-keyword_$id";
                $action_name = "rssdoodle-edit_keyword";
                $after = "<input type=\"hidden\" name=\"id\" value=\"{$keyword['id']}\" />\n";
				
            } else {
                $header = __('Add new keyword search', 'rssdoodle');
                $header_text = __('Add the blog RSS feed you want your posts to contain.', 'rssdoodle');
                $button_text = __('Add blog RSS feed  &raquo;', 'rssdoodle');
                $nonce_name = "new-keyword";
                $action_name = "rssdoodle-new_keyword";
                $after = '';
                
                $keyword = array( 'keyword'=>'', 'update_interval'=>'', 'front_page'=>'Y', 
                                  'category_id'=>$this->get_option('default_category'), 
                                  'do_pings'=>$this->get_option('do_pings_default'));
            }
						
            add_filter('wp_dropdown_cats', array(&$this, 'new_cat_option'));
        ?>
		
			<script type="text/javascript">
				function checkurls(){
					var len=10;
					var i;
					var j=0;
					for(i=0;i<len;i++){
						var feedurl="search_string-"+i;
						if(document.getElementById(feedurl).value!=''){
							j++;
						}
					}
					if(j >= 3){
						return true;
					}else{
						alert('Please enter minimum three blog RSS feed urls...');
						return false;
					}	
				}
			
			</script>


            <h2><?php echo $header ?></h2>
            <p><?php echo $header_text ?></p>
            <form method="post" action="admin.php?page=rssdoodle_multifeed_options">
			<input type="hidden" size="40" value="multiblog" id="type" name="type"/>

                <table width="100%" cellspacing="2" cellpadding="5" class="editform form-table"><tbody>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="keyword"><?php _e('Post feed Name', 'rssdoodle'); ?>:</label><br /><span style="font-size:xx-small">Post title will be "Posts about <em>feed name</em>"</span></th>
            			<td width="67%"><input type="text" size="40" value="<?php echo htmlentities($keyword['keyword']) ?>" id="keyword" name="keyword"/></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="search_string"><?php _e('Blog RSS Feed URL', 'rssdoodle'); ?>:</label><br /><span style="font-size:xx-small">Add RSS feeds URLs <br/>e.g. "http://feed.digitalcameraphotographynews.com/digitalcameraphotography"</span></th>
            			<td width="67%">
						<?php $MulFurl=explode(',',$keyword['search_string']);
						for($i=0;$i<10;$i++){ ?>
						<input type="text" size="80" value="<?php echo htmlentities($MulFurl[$i]) ?>" id="search_string-<?php echo $i;?>" name="search_string[]"/>
						<? }?>
						</td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="keyword"><?php _e('Category', 'rssdoodle'); ?>:</label></th>
            			<td width="67%"><?php wp_dropdown_categories("hide_empty=0&selected={$keyword['category_id']}") ?></td>
            		</tr>
<tr>
            			<th width="33%" valign="top" scope="row"><label for="keyword"><?php _e('Tags', 'rssdoodle'); ?>:</label><br /><span style="font-size:xx-small">Enter the tags with comma separated text e.g. "RSSdoodle, Keyword, Tag "</span></th>
            			<td width="67%">	
						<input type="text" size="40" value="<?php echo htmlentities($keyword['tag_id']) ?>" id="tag" name="tag"/>	
					</td>
            		</tr>            		
					<tr>
            			<th width="33%" valign="top" scope="row"><label for="update_interval"><?php _e('Interval', 'rssdoodle'); ?>:</label></th>
            			<td width="67%"><?php $this->interval_dropdown($keyword['update_interval']) ?></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="front_page"><?php _e('Front Page', 'rssdoodle'); ?>:</label></th>
            			<td width="67%"><input type="checkbox" <?php checked($keyword['front_page'], 'Y') ?> id="front_page" name="front_page"/></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="do_pings"><?php _e('Do Pings', 'rssdoodle'); ?>:</label></th>
            			<td width="67%"><input type="checkbox" <?php checked($keyword['do_pings'], 'Y') ?> id="do_pings" name="do_pings"/></td>
            		</tr>
            	</tbody></table>
                <p class="submit"><input type="submit" value="<?php echo $button_text ?>" name="<?php echo $action_name ?>" onclick="return checkurls();" /></p>
                <?php wp_nonce_field($nonce_name) ?>
                <?php echo $after ?>
            </form>
            
        </div>
		
		<?php

	 }
	 
    /**
	 * Do the news feed options page.
	 */
	 function newsfeed_options_page() {
        global $wpdb;

        $options = get_option('rssdoodle');
		
        $keyword_query = "SELECT * FROM {$this->keyword_table} where keyword_type='news' ";
        $keywords = $wpdb->get_results($keyword_query);

        if( empty($keywords) ) {
            echo '<div class="error"><p>';
            _e('There is no keywords to search with google news feed.', 'rssdoodle');
            echo '</p></div>';
        }
		
        // Show a message if needed
        if( isset($_GET['rssdoodle_message']) ) {
            $messages = array( '1' => __("Successfully added the keyword search <b>%s</b>", 'rssdoodle'),
                               '2' => __("Successfully edited the keyword search <b>%s</b>", 'rssdoodle'),
                               '3' => __("Successfully deleted the keyword search <b>%s</b>", 'rssdoodle'),
                               '4' => __("Successfully updated the options", 'rssdoodle'));
            $keyword = @stripslashes($_GET['keyword_name']);
            $msg = sprintf($messages[$_GET['rssdoodle_message']], $keyword);

            echo "<div id=\"message\" class=\"updated fade\"><p>$msg</p></div>";
        }

        ?>
        <div class="wrap">
        <h2><?php _e("News Feed Keyword Searches", "rssdoodle"); ?></h2>
        <p></p>
        <table class="widefat">
        <thead>
            <tr>
                <th><?php _e('Post Keyword Name', 'rssdoodle'); ?></th>
                <th><?php _e('Keyword or Keyword String', 'rssdoodle'); ?></th>
                <th><?php _e('Category', 'rssdoodle'); ?></th>
				<th><?php _e('Tags', 'rssdoodle'); ?></th>
                <th><?php _e('Interval', 'rssdoodle'); ?></th>
                <th><?php _e('Front Page', 'rssdoodle'); ?></th>
                <th colspan="2"><?php _e('Actions', 'rssdoodle'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php
        if( empty($keywords) ) {
            ?>
            <tr colspan="5"><td><?php _e('You currently have no keyword searches.  Add one below!', 'rssdoodle') ?></td></tr>
            <?php
        } else {
            $class = "";
            foreach( $keywords as $kw ) {
                echo "<tr id=\"search-{$kw->id}\" class=\"$class\">";
                echo "<td>". $kw->keyword."</td>\n";
                echo "<td>". $kw->search_string."</td>\n";
                echo "<td>".get_cat_name($kw->category_id)."</td>\n";
				echo "<td>". $kw->tag_id ."</td>\n";
                echo "<td>{$kw->update_interval}</td>\n";
                echo "<td>". ($kw->front_page=='Y' ? 'Yes' : 'No') ."</td>\n";
                echo "<td><a class='view' href='admin.php?page=rssdoodle_newsfeed_options&amp;rssdoodle_edit={$kw->id}#rssdoodle_edit'>Edit</a></td>\n";
                echo "<td><a class='delete' href='".wp_nonce_url("admin.php?page=rssdoodle_newsfeed_options&amp;rssdoodle-delete_keyword={$kw->id}&amp;keyword_name={$kw->keyword}", 'delete-keyword_' . $kw->id)."' onclick=\"return confirm('" . js_escape(sprintf( __("You are about to delete the keyword '%s'.\n'OK' to delete, 'Cancel' to stop.", 'rssdoodle'), $kw->keyword)) . "' );\">Delete</a></td>\n";
                echo "</tr>";
                $class = empty($class)?"alternate":"";
            }
        }        
        ?>
        </tbody>
        </table>
        </div>
        <div class="wrap narrow" id="rssdoodle_edit">
        <?php
            if( isset($_GET['rssdoodle_edit']) ) {
                $id = $_GET['rssdoodle_edit'];

                $query = "SELECT * FROM {$this->keyword_table} WHERE id=$id";
                $keyword = $wpdb->get_row($query, ARRAY_A);

                $header = __(sprintf('Edit keyword search: %s', $keyword['keyword']), 'rssdoodle');
                $header_text = __('Add the keywords or key word phrases you want your posts to contain.', 'rssdoodle');
                $button_text = __('Edit Keyword Search &raquo;', 'rssdoodle');
                $nonce_name = "edit-keyword_$id";
                $action_name = "rssdoodle-edit_keyword";
                $after = "<input type=\"hidden\" name=\"id\" value=\"{$keyword['id']}\" />\n";
				
            } else {
                $header = __('Add new keyword search', 'rssdoodle');
                $header_text = __('Add the keywords or key word phrases you want your posts to contain.', 'rssdoodle');
                $button_text = __('Add Keyword Search &raquo;', 'rssdoodle');
                $nonce_name = "new-keyword";
                $action_name = "rssdoodle-new_keyword";
                $after = '';
                
                $keyword = array( 'keyword'=>'', 'update_interval'=>'', 'front_page'=>'Y', 
                                  'category_id'=>$this->get_option('default_category'), 
                                  'do_pings'=>$this->get_option('do_pings_default'));
            }
            add_filter('wp_dropdown_cats', array(&$this, 'new_cat_option'));
        ?>
            <h2><?php echo $header ?></h2>
            <p><?php echo $header_text ?></p>
            <form method="post" action="admin.php?page=rssdoodle_newsfeed_options">
			<input type="hidden" size="40" value="news" id="type" name="type"/>
			
                <table width="100%" cellspacing="2" cellpadding="5" class="editform form-table"><tbody>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="keyword"><?php _e('Post Keyword Name', 'rssdoodle'); ?>:</label><br /><span style="font-size:xx-small">Post title will be "News about <em>Keyword</em>"</span></th>
            			<td width="67%"><input type="text" size="40" value="<?php echo htmlentities($keyword['keyword']) ?>" id="keyword" name="keyword"/></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="search_string"><?php _e('Keyword or Keyword String', 'rssdoodle'); ?>:</label><br /><span style="font-size:xx-small">e.g. "Barack Obama", "Obama OR Clinton", "Obama AND baseball"</span></th>
            			<td width="67%"><input type="text" size="40" value="<?php echo htmlentities($keyword['search_string']) ?>" id="search_string" name="search_string"/></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="keyword"><?php _e('Category', 'rssdoodle'); ?>:</label></th>
            			<td width="67%"><?php wp_dropdown_categories("hide_empty=0&selected={$keyword['category_id']}") ?></td>
            		</tr>
<tr>
            			<th width="33%" valign="top" scope="row"><label for="keyword"><?php _e('Tags', 'rssdoodle'); ?>:</label><br /><span style="font-size:xx-small">Enter the tags with comma separated text e.g. "RSSdoodle, Keyword, Tag "</span></th>
            			<td width="67%">	
						<input type="text" size="40" value="<?php echo htmlentities($keyword['tag_id']) ?>" id="tag" name="tag"/>
						
					</td>
            		</tr>            		
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="update_interval"><?php _e('Interval', 'rssdoodle'); ?>:</label></th>
            			<td width="67%"><?php $this->interval_dropdown($keyword['update_interval']) ?></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="front_page"><?php _e('Front Page', 'rssdoodle'); ?>:</label></th>
            			<td width="67%"><input type="checkbox" <?php checked($keyword['front_page'], 'Y') ?> id="front_page" name="front_page"/></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="do_pings"><?php _e('Do Pings', 'rssdoodle'); ?>:</label></th>
            			<td width="67%"><input type="checkbox" <?php checked($keyword['do_pings'], 'Y') ?> id="do_pings" name="do_pings"/></td>
            		</tr>
            	</tbody></table>
                <p class="submit"><input type="submit" value="<?php echo $button_text ?>" name="<?php echo $action_name ?>"/></p>
                <?php wp_nonce_field($nonce_name) ?>
                <?php echo $after ?>
            </form>
            
            
        </div>
        <?php
    
		 
		}

    /**
	 * Do the video feed options page.
	 */
	 function videofeed_options_page() {

        global $wpdb;

        $options = get_option('rssdoodle');
		
        $keyword_query = "SELECT * FROM {$this->keyword_table} where keyword_type='video' ";
        $keywords = $wpdb->get_results($keyword_query);

        if( empty($keywords) ) {
            echo '<div class="error"><p>';
            _e('There is no keywords to search with google videos.', 'rssdoodle');
            echo '</p></div>';
        }
		
        // Show a message if needed
        if( isset($_GET['rssdoodle_message']) ) {
            $messages = array( '1' => __("Successfully added the keyword search <b>%s</b>", 'rssdoodle'),
                               '2' => __("Successfully edited the keyword search <b>%s</b>", 'rssdoodle'),
                               '3' => __("Successfully deleted the keyword search <b>%s</b>", 'rssdoodle'),
                               '4' => __("Successfully updated the options", 'rssdoodle'));
            $keyword = @stripslashes($_GET['keyword_name']);
            $msg = sprintf($messages[$_GET['rssdoodle_message']], $keyword);

            echo "<div id=\"message\" class=\"updated fade\"><p>$msg</p></div>";
        }

        ?>
        <div class="wrap">
        <h2><?php _e("Video Feed Keyword Searches", "rssdoodle"); ?></h2>
        <p></p>
        <table class="widefat">
        <thead>
            <tr>
                <th><?php _e('Post Keyword Name', 'rssdoodle'); ?></th>
                <th><?php _e('Keyword or Keyword String', 'rssdoodle'); ?></th>
                <th><?php _e('Category', 'rssdoodle'); ?></th>
				<th><?php _e('Tags', 'rssdoodle'); ?></th>
                <th><?php _e('Interval', 'rssdoodle'); ?></th>
                <th><?php _e('Front Page', 'rssdoodle'); ?></th>
                <th colspan="2"><?php _e('Actions', 'rssdoodle'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php
        if( empty($keywords) ) {
            ?>
            <tr colspan="5"><td><?php _e('You currently have no keyword searches.  Add one below!', 'rssdoodle') ?></td></tr>
            <?php
        } else {
            $class = "";
            foreach( $keywords as $kw ) {
                echo "<tr id=\"search-{$kw->id}\" class=\"$class\">";
                echo "<td>". $kw->keyword."</td>\n";
                echo "<td>". $kw->search_string."</td>\n";
                echo "<td>".get_cat_name($kw->category_id)."</td>\n";
				echo "<td>". $kw->tag_id ."</td>\n";
                echo "<td>{$kw->update_interval}</td>\n";
                echo "<td>". ($kw->front_page=='Y' ? 'Yes' : 'No') ."</td>\n";
                echo "<td><a class='view' href='admin.php?page=rssdoodle_videofeed_options&amp;rssdoodle_edit={$kw->id}#rssdoodle_edit'>Edit</a></td>\n";
                echo "<td><a class='delete' href='".wp_nonce_url("admin.php?page=rssdoodle_videofeed_options&amp;rssdoodle-delete_keyword={$kw->id}&amp;keyword_name={$kw->keyword}", 'delete-keyword_' . $kw->id)."' onclick=\"return confirm('" . js_escape(sprintf( __("You are about to delete the keyword '%s'.\n'OK' to delete, 'Cancel' to stop.", 'rssdoodle'), $kw->keyword)) . "' );\">Delete</a></td>\n";
                echo "</tr>";
                $class = empty($class)?"alternate":"";
            }
        }        
        ?>
        </tbody>
        </table>
        </div>
        <div class="wrap narrow" id="rssdoodle_edit">
        <?php
            if( isset($_GET['rssdoodle_edit']) ) {
                $id = $_GET['rssdoodle_edit'];

                $query = "SELECT * FROM {$this->keyword_table} WHERE id=$id";
                $keyword = $wpdb->get_row($query, ARRAY_A);

                $header = __(sprintf('Edit keyword search: %s', $keyword['keyword']), 'rssdoodle');
                $header_text = __('Add the keywords or key word phrases you want your posts to contain.', 'rssdoodle');
                $button_text = __('Edit Keyword Search &raquo;', 'rssdoodle');
                $nonce_name = "edit-keyword_$id";
                $action_name = "rssdoodle-edit_keyword";
                $after = "<input type=\"hidden\" name=\"id\" value=\"{$keyword['id']}\" />\n";
				
            } else {
                $header = __('Add new keyword search', 'rssdoodle');
                $header_text = __('Add the keywords or key word phrases you want your posts to contain.', 'rssdoodle');
                $button_text = __('Add Keyword Search &raquo;', 'rssdoodle');
                $nonce_name = "new-keyword";
                $action_name = "rssdoodle-new_keyword";
                $after = '';
                
                $keyword = array( 'keyword'=>'', 'update_interval'=>'', 'front_page'=>'Y', 
                                  'category_id'=>$this->get_option('default_category'), 
                                  'do_pings'=>$this->get_option('do_pings_default'));
            }
            add_filter('wp_dropdown_cats', array(&$this, 'new_cat_option'));
        ?>
            <h2><?php echo $header ?></h2>
            <p><?php echo $header_text ?></p>
            <form method="post" action="admin.php?page=rssdoodle_videofeed_options">
			<input type="hidden" size="40" value="video" id="type" name="type"/>
			
                <table width="100%" cellspacing="2" cellpadding="5" class="editform form-table"><tbody>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="keyword"><?php _e('Post Keyword Name', 'rssdoodle'); ?>:</label><br /><span style="font-size:xx-small">Post title will be "Videos about <em>Keyword</em>"</span></th>
            			<td width="67%"><input type="text" size="40" value="<?php echo htmlentities($keyword['keyword']) ?>" id="keyword" name="keyword"/></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="search_string"><?php _e('Keyword or Keyword String', 'rssdoodle'); ?>:</label><br /><span style="font-size:xx-small">e.g. "Barack Obama", "Obama OR Clinton", "Obama AND baseball"</span></th>
            			<td width="67%"><input type="text" size="40" value="<?php echo htmlentities($keyword['search_string']) ?>" id="search_string" name="search_string"/></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="keyword"><?php _e('Category', 'rssdoodle'); ?>:</label></th>
            			<td width="67%"><?php wp_dropdown_categories("hide_empty=0&selected={$keyword['category_id']}") ?></td>
            		</tr>
					<tr>
            			<th width="33%" valign="top" scope="row"><label for="keyword"><?php _e('Tags', 'rssdoodle'); ?>:</label><br /><span style="font-size:xx-small">Enter the tags with comma separated text e.g. "RSSdoodle, Keyword, Tag "</span></th>
            			<td width="67%">	
						<input type="text" size="40" value="<?php echo htmlentities($keyword['tag_id']) ?>" id="tag" name="tag"/>
						
					</td>
            		</tr>            		


            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="update_interval"><?php _e('Interval', 'rssdoodle'); ?>:</label></th>
            			<td width="67%"><?php $this->interval_dropdown($keyword['update_interval']) ?></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="front_page"><?php _e('Front Page', 'rssdoodle'); ?>:</label></th>
            			<td width="67%"><input type="checkbox" <?php checked($keyword['front_page'], 'Y') ?> id="front_page" name="front_page"/></td>
            		</tr>
            		<tr>
            			<th width="33%" valign="top" scope="row"><label for="do_pings"><?php _e('Do Pings', 'rssdoodle'); ?>:</label></th>
            			<td width="67%"><input type="checkbox" <?php checked($keyword['do_pings'], 'Y') ?> id="do_pings" name="do_pings"/></td>
            		</tr>
            	</tbody></table>
                <p class="submit"><input type="submit" value="<?php echo $button_text ?>" name="<?php echo $action_name ?>"/></p>
                <?php wp_nonce_field($nonce_name) ?>
                <?php echo $after ?>
            </form>
            
            
        </div>
        <?php
	 }

    /**
     * Filter for the wp_dropdown_cats function to add a 'new category' option.
     */
    function new_cat_option($cat_dropdown) {
        $replacement = '<option value="new">'.__('New Category', 'rssdoodle').'</option></select>';
        return str_replace('</select>', $replacement, $cat_dropdown);
    }
    
    /**
     * Displays a dropdown with the possible intervals.
     */
    function interval_dropdown($current=False) {
        $options = array("Time-based" => array('daily'=>'Every day', '3days'=>'Every 3 days', 'weekly'=>'Weekly'),
                         'Count-based' => array('5posts'=>'5 Posts', '10posts'=>'10 Posts', '50posts'=>'50 Posts'));

        echo "<select id=\"update_interval\" name=\"update_interval\">";
        foreach( $options as $name=>$intervals ) {
            echo "<optgroup label=\"$name\">\n";
            foreach( $intervals as $key=>$display ) {
                echo '<option ';
                selected($current,$key);
                echo " value=\"$key\">$display</option>\n";
            }
            echo "</optgroup>\n";
        }
        echo "</select>\n";
    }
    
    /**
     * Posts all of the count-based keywords.
     */
    function post_count_posts() {
        global $wpdb;
        
        // Clever query that returns all count-based keywords with the minimum number of posts satisfied
        $keywords_query  = "SELECT {$this->keyword_table}.*, COUNT({$this->keyword_table}.id) AS post_count ";
        $keywords_query .= "FROM {$this->keyword_table} ";
        $keywords_query .= "JOIN {$this->posts_table} ON {$this->keyword_table}.id = {$this->posts_table}.keyword_id ";
        $keywords_query .= "WHERE update_interval LIKE '%posts' ";
        $keywords_query .= "GROUP BY {$this->keyword_table}.id ";
        $keywords_query .= "HAVING post_count >= CONVERT(TRIM(TRAILING 'posts' FROM update_interval), SIGNED)";        

        $keywords = $wpdb->get_results($keywords_query);
        kses_remove_filters(); // Allows us to post div's
        foreach( $keywords as $kw ) {
            $this->post_keyword($kw->id, $kw);
        }
    }
    

    /**
     * Posts a keyword.
     *
     * $kw object Keyword from database, if it's already been retrieved.
     */
    function post_keyword($id, $kw=NULL) {
        global $wpdb;

        $posts_query = "SELECT * FROM {$this->posts_table} WHERE keyword_id={$id} AND  posted='N' ORDER BY post_date ".$this->get_option('comment_order');

        if( $this->get_option('max_posts_per_post') != 0 ) {
            $posts_query .= " LIMIT ".$this->get_option('max_posts_per_post');
        }
        $posts = $wpdb->get_results($posts_query);
		
        if( !empty( $posts ) ) {
            if( $kw == NULL )
                $kw = $wpdb->get_row("SELECT * FROM {$this->keyword_table} WHERE id={$id}");


            $post_array = $this->build_post_array($posts,$kw);

            if( $kw->do_pings=='N' ) {
                remove_action('publish_post', '_publish_post_hook', 5, 1);
            }

            if( !empty($post_array)) {
                $result = wp_insert_post($post_array);
                if( $result != 0 ) {
                    if($kw->front_page=='N') {
                        $data = array( 'post_id' => $result, 'meta_key' => '_rssdoodle_fp_exclude', 'meta_value' => '1' );
                		$wpdb->insert( $wpdb->postmeta, $data );
                    }
					
            		$data = array( 'post_id' => $result, 'meta_key' => '_rssdoodle_post', 'meta_value' => '1' );
            		$wpdb->insert( $wpdb->postmeta, $data );
        		}
            }

            // Delete the posts that were posted.
            // $ids = implode(',',array_map(create_function('$t', 'return $t->id;'), $posts));
            $update_query = "UPDATE {$this->posts_table} SET posted='Y' WHERE keyword_id={$id}";
            $wpdb->query($update_query);
			// Update the issue count of the keyword that were posted.
			$update_keyquery = "UPDATE {$this->keyword_table} SET last_count=last_count+1 where id={$id}";
			$wpdb->query($update_keyquery);

        } else {
            $this->update_option('last_error', "No posts for $id");
        }
    }
    
	

    /**
     * Utility function to get a rssdoodle option.
     */
    function get_option($name) {
        if( $name=='technorati_key' && defined('RSSDOODLE_TECHNORATI_API_KEY' ) ) 
            return RSSDOODLE_TECHNORATI_API_KEY;
        $options = get_option('rssdoodle');
        return $options[$name];
    }
    
    /**
     * Utility funciton to set a rssdoodle options.
     */
    function update_option($name, $value) {
        if( $name=='technorati_key' && defined('RSSDOODLE_TECHNORATI_API_KEY' ) ) return;
        $options = get_option('rssdoodle');
        $options[$name] = $value;
        update_option('rssdoodle',$options);
    }
    
    /**
     * Creates the posts for a given keyword and set of posts.
     */
    function build_post_array($posts,$keyword,$rsstype='') {
        global $wpdb;

        $post = array('post_status' => 'publish', 'post_type' => 'post',
    		'ping_status' => get_option('default_ping_status'), 'post_parent' => 0,
    		'menu_order' => 0);

        $date = mysql2date(get_option('date_format'), current_time('mysql'));

    	$post['post_category'] = array($keyword->category_id);
        $post['post_author'] = $keyword->user_id;

        if($rsstype==''){
			if( !empty($keyword->keyword) ) {
				$count=$keyword->last_count+1;
				if($keyword->keyword_type == 'video' )
					$post['post_title'] = "Videos about {$keyword->keyword} issue #{$count}";
				else if($keyword->keyword_type == 'news' )
					$post['post_title'] = "News about {$keyword->keyword} issue #{$count}";
				else if($keyword->keyword_type == 'multiblog' )
					$post['post_title'] = "Posts about {$keyword->keyword} issue #{$count}";
				else
					$post['post_title'] = "Articles about {$keyword->keyword} issue #{$count}";
					
					$post['post_title'] = str_replace('"', '', $post['post_title']);
					
					$post['tags_input'] = $keyword->tag_id;
			} else {
				return array();
			}
		}else{
			if( !empty($keyword->keyword) ) {
				$count=$keyword->last_count+1;
				$post['post_title'] = "Posts about {$keyword->keyword} issue #{$count}";
				$post['post_title'] = str_replace('"', '', $post['post_title']);
				$post['tags_input'] = $keyword->tag_id;
			} else {
				return array();
			}
		}

    	$content = '<div class="rssdoodle">';
    	foreach( $posts as $a_post ) {
			$url_comp = parse_url($a_post->blog_url);
			
    	    $host = str_replace('www.', '', $url_comp['host']);
			if($keyword->keyword_type == 'news')
			$a_post->post_title = str_ireplace('- '.$host, '', $a_post->post_title);
			
    	    $content .= "<div class=\"doodle_post\">\n";
    	    $content .= "<div class=\"doodle_meta\"><a rel='nofollow' href=\"{$a_post->permalink}\">{$a_post->post_title}</a> - <em>{$host}</em></div>";
    	    $content .= '<div class="doodle_date">'.mysql2date('m/d/Y', $a_post->post_date)."</div>";
			
            $content .= '<div class="doodle_post">'.$a_post->post_excerpt;
            $content .= " <a rel='nofollow' href=\"{$a_post->permalink}\"> more... </a></div>\n</div>\n";
    	}
        if( $this->get_option('links_nofollow') === False) {
            $content = str_replace(array(' rel="nofollow"', " rel='nofollow'"), '', $content);
        }
        if( $this->get_option('links_newwindow') == True) {
            $content = str_replace('<a ', '<a target="_blank" ', $content);
        }
		if($keyword->keyword_type == 'news' ){
			$footer = apply_filters('rssdoodle_newsfooter', array('<a href="http://www.lessnau.com/rssdoodle/" rel="nofollow" >RSSdoodle</a> by <a href="http://www.lessnau.com/" >The Lessnau Lounge</a>.') );
		}else if($keyword->keyword_type == 'video' ){
			$footer = apply_filters('rssdoodle_videofooter', array('<a href="http://www.lessnau.com/rssdoodle/" rel="nofollow" >RSSdoodle</a> by <a href="http://www.lessnau.com/"  >The Lessnau Lounge</a>.') );

		}else if ($keyword->keyword_type == 'multiblog' ){
			$footer = apply_filters('rssdoodle_blogfooter', array('<a href="http://www.lessnau.com/rssdoodle/" rel="nofollow" >RSSdoodle</a> by <a href="http://www.lessnau.com/"  >The Lessnau Lounge</a>.') );
		}else{
			$footer = apply_filters('rssdoodle_footer', array('RSSdoodle by <a href="http://www.lessnau.com/rssdoodle/">The Lessnau Lounge</a>.') );
		}
		$content .= '<div class="rssdoodle_footer">'.implode(' ', $footer).'</div>';
    	$content .= '</div>';

    	$post['post_content'] = $wpdb->escape($content);

    	$post = apply_filters('rssdoodle_post_array', $post);

    	return $post;
    }

	function is_host_blocked($host) {
		$host_len = strlen($host);
		$blocks = $this->get_option('blocked');
		foreach( $blocks as $b ) {
			if($host){
				if( @strpos($host, $b)==($host_len-strlen($b)) ) { // ends_with
					return true;
				}
			}
		}
		return false;
	}

    /**
     * Updates everything that needs to be updated:
     * * count-based keywords
     * * time-based keywords
     */
    function update_posts($keyword_id=NULL) {
        global $wpdb;
        
        $query = "SELECT id, search_string, keyword_type FROM {$this->keyword_table}";
        if( !is_null($keyword_id) ) {
            $query .= " WHERE id=$keyword_id";
        }
        $keywords = $wpdb->get_results($query);

        if( empty($keywords) ) {
            $this->update_option('last_error', "No keywords needing updating");
            return;
        }

        foreach( $keywords as $kw ) {
            // Get the posts for the keyword
			if(trim($kw->keyword_type)=='news'){
				
				$results = $this->snooper->get_newsfeedposts($kw->search_string,$this->get_option('max_posts_per_post'));
				
			 } else if(trim($kw->keyword_type)=='video'){
				
				$results = $this->snooper->get_videofeedposts($kw->search_string,$this->get_option('max_posts_per_post'));
				
			 } else if(trim($kw->keyword_type)=='multiblog'){
				
				$results = $this->snooper->get_feedposts($kw->search_string,$this->get_option('max_posts_per_post'));
				
			 } 
            // If there were new posts, insert them into the database in one go

            if( !empty($results ) ) {
                $insert  = "INSERT IGNORE INTO {$this->posts_table} (blog_name,";
                $insert .= "blog_url,post_title,post_excerpt";
                $insert .= ",post_date,permalink, post_type, keyword_id) VALUES ";

                $values = array();
                foreach( $results as $a_post ) {
					$url_comp = parse_url($a_post['url']);
		    	    $host = str_replace('www.', '', $url_comp['host']);
					if( $this->is_host_blocked($host)) continue;
	
                    $aValue  = "('". $wpdb->escape($a_post['name']);
                    $aValue .= "','". $wpdb->escape($a_post['url']);
                    $aValue .= "','". $wpdb->escape($a_post['title']);
					
					if(trim($kw->keyword_type)=='video'){
						$Pagevideo = '';
						$Pagevideo = str_replace('&feature=youtube_gdata','',$a_post['url']); 
						$Pagevideo = str_replace('http://www.youtube.com/watch?v=','http://www.youtube.com/v/',$Pagevideo );
						$pgvideo = '<br/><object width="361" height="184"><param name="movie" value="'.$Pagevideo.'&hl=en_US&fs=1&rel=0"></param><param name="allowFullScreen" value="true"></param><param name="allowscriptaccess" value="always"></param><embed src="'.$Pagevideo.'&hl=en_US&fs=1&rel=0" type="application/x-shockwave-flash" allowscriptaccess="always" allowfullscreen="true" width="361" height="184" wmode="transparent"></embed></object>';
						$aValue .= "','". $wpdb->escape(trim($a_post['excerpt'])).$pgvideo;	
						
					} else {
						$aValue .= "','". $wpdb->escape(trim($a_post['excerpt']));
					}
					
                    $aValue .= "','". $wpdb->escape($a_post['created']);
                    $aValue .= "','". $wpdb->escape($a_post['permalink']);
					$aValue .= "','". $wpdb->escape($kw->keyword_type);
                    $aValue .= "','". $kw->id ."')";

                    $values []= $aValue;
                }
                $insert .= implode(',', $values);
                $success = $wpdb->query($insert);
                if( !$success ) {
                    $this->update_option('last_error', "Could not update posts table");
                }

                // Update the keyword with the most recent search
                $update_keyword  = "UPDATE {$this->keyword_table} SET ";
                $update_keyword .= "last_searched='". current_time('mysql') ."' ";
                $update_keyword .= "WHERE id={$kw->id}";

                $success = $wpdb->query($update_keyword);
                if( !$success ) {
                    $this->update_option('last_error', "Could not update keyword table");
                }
                
            } else {
                $this->update_option('last_error', "Got empty results back");
            }
        }
    } 
}

class RSSdoodle_Feed {
    var $snoop;
    var $parse_results;

    function RSSdoodle_Feed() {
        add_filter('rssdoodle_footer', array(&$this, 'attribution'));
		add_filter('rssdoodle_newsfooter', array(&$this, 'newsattribution'));
		add_filter('rssdoodle_videofooter', array(&$this, 'videoattribution'));
		add_filter('rssdoodle_blogfooter', array(&$this, 'blogattribution'));
    }
    
    function attribution($attrib) {
        $attrib []= 'Results provided by <a href="http://www.lessnau.com/rssdoodle/" rel="nofollow" >RSSdoodle</a>.';
        return $attrib;
    }
	function newsattribution($attrib) {
        $attrib []= ' Results provided by <a href="http://news.google.com/" rel="nofollow" > Google News</a>.';
        return $attrib;
    }
	function videoattribution($attrib) {
        $attrib []= ' Results provided by <a href="http://www.youtube.com/" rel="nofollow" > YouTube</a>.';
        return $attrib;
    }

	function blogattribution($attrib) {
        $attrib []= ' Results provided by favorite bloggers RSS feeds.';
        return $attrib;
    }

	function get_newsfeedposts( $search , $pstcnt='10') {
		global $wpdb;
		$this->parse_results = array();
		$search =urlencode($search);
		$urls='http://news.google.com/news?q='.$search.'&output=rss&num='.$pstcnt;
		$this->parse_results=$this->fetch_rss_feed($urls,$pstcnt,20,'news');
		$this->parse_results=$this->array2object($this->parse_results);
        return $this->parse_results;
	 }	
	 
	function get_videofeedposts( $search , $pstcnt='10') {
		global $wpdb;
		$this->parse_results = array();
		$search =urlencode($search.' -game -hacks');
		$urls='http://gdata.youtube.com/feeds/base/videos?q='.$search.'&uploaded=d&client=ytapi-youtube-search&alt=rss&v=2&time=today&max-results='.$pstcnt;
		$this->parse_results=$this->fetch_rss_feed($urls,$pstcnt,20,'video');
        return $this->parse_results;

	 }		 
	 
	 function get_feedposts( $feedurl,$pstcnt='10' ) {
	 global $wpdb;
		$this->parse_results = array();
		$results1 = array();
		$result=$results = array();
		$urls= explode(',', $feedurl);
		 if($urls){
			  foreach( $urls as $url ) {
			  	$result=$this->fetch_rss_feed($url,$pstcnt,20);
				  if($result){
					  if($results1)
						 $results1=array_merge($results1,$result);
					  else
						$results1=$result;
						
				  }	
				  shuffle($results1);
			  }
		  }else{
			$results1=$this->fetch_rss_feed($feedurl,$pstcnt,20);
		}
		shuffle($results1);
		$this->parse_results=$this->array2object($results1);
		
		return $this->parse_results;
    }
	
function array2object($array) {
 
    if (is_array($array)) {
        $obj = new StdClass();
 
        foreach ($array as $key => $val){
            $obj->$key = $val;
        }
    }
    else { $obj = $array; }
 
    return $obj;
}

function fetch_rss_feed ($url, $num=5, $length=23,$feedtype='') {
	//ini_set("display_errors", false); uncomment to suppress php errors thrown if the feed is not returned.
	if ( file_exists(ABSPATH . WPINC . '/rss.php') )
		require_once(ABSPATH . WPINC . '/rss.php');
	else
		require_once(ABSPATH . WPINC . '/rss-functions.php');
	
	$rss = fetch_rss($url);
	$num_items = $num;
	$lp=0;
	
	if ( $rss ) {
		$rss->items = array_slice($rss->items, 0, $num_items);
		
		foreach ($rss->items as $item ) {
			
			if($feedtype=='video'){	
				$res[$lp]['created'] = date("Y-m-d H:i:s", strtotime($item['pubdate']));  
				$res[$lp]['url']= $item['link']; 
				$res[$lp]['permalink']= $item['link'];
				$res[$lp]['category'] = $item['category']; 
				$anchortagpattern = '/(<div style="font-size: 12px; margin: 3px 0px;"><span>)(.*)(<\/span><\/div><\/td>)/ismU';
				$strcontents=$item['description'];
				if(preg_match_all($anchortagpattern, $strcontents, $matchedoutputs, PREG_SET_ORDER)) {
					$description = $matchedoutputs[0][0];
				}
				$item_description = strip_tags($description); 
				$item_description = stripslashes($item_description); 
				$item_description = substr($item_description,0,200);
				$item_description = $item_description;
				$res[$lp]['excerpt'] = str_replace('"', '', $item_description);
				$res[$lp]['name']= $item['title_em'].wp_specialchars($item['title']);
				$res[$lp]['title']= $item['title_em'].wp_specialchars($item['title']);
				$lp++;
			}else{
				if(  $item['description'] != '' || $item['pubdate'] != ''  ){
			
					$res[$lp]['created'] = date("Y-m-d H:i:s", strtotime($item['pubdate']));  
					$item['guid']  = empty($item['guid']) ? $item['link']:$item['guid'];
					$res[$lp]['url']= $item['guid']; 
					if($feedtype=='news') 
						$urls=explode('cluster=',$item['guid']);
					if( $urls ) {
						$res[$lp]['url']=$urls[1];
					}
					if($feedtype=='news')
						$res[$lp]['permalink']= $res[$lp]['url'];
					else
						$res[$lp]['permalink']= $item['link'];

					$res[$lp]['category'] = $item['category']; 
					$item_description = strip_tags($item['description']); 
					$item_description = stripslashes($item_description); 
					$item_description = substr($item_description,0,200);
					$item_description = $item_description;
					$res[$lp]['excerpt'] = str_replace('"', '', $item_description);
					$res[$lp]['name']= $item['title_em'].wp_specialchars($item['title']);
					$res[$lp]['title']= $item['title_em'].wp_specialchars($item['title']);
					$lp++;
					
				} else if(   $item['atom_content']!='' || $item['published'] != '' ) {
					$res[$lp]['created'] = date("Y-m-d H:i:s", strtotime($item['published']));  
					$res[$lp]['url']= $item['link']; 
					$res[$lp]['permalink']= $item['link'];
					$res[$lp]['category'] = $item['category']; 
					$item_description = strip_tags($item['atom_content']); 
					$item_description = stripslashes($item_description); 
					$item_description = substr($item_description,0,200);
					$item_description = $item_description;
					$res[$lp]['excerpt'] = str_replace('"', '', $item_description);
					$res[$lp]['name']= wp_specialchars($item['title']);
					$res[$lp]['title']= wp_specialchars($item['title']);
					$lp++;
				}	

			}	
		}		
	}
		$results=$res;
        return $results;
}

}
// Gets the show on the road.
new RSSdoodle();
?>