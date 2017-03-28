<?php
/*
Plugin Name: Assignments
Plugin URI: https://github.com/MikhailRoot/Assignments
Description: Plugin to handle tasks assignments from admin to editors
Author: Mikhail Durnev
Version: 0.1
Author URI: https://mikhailroot.ru/
*/
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


class Assignments {

	/* @var Assignments|null */
	protected static $instance=null;

	protected static $writers=[];

	protected function __construct()
	{
		$this->initHooks();
	}

	protected function initHooks()
	{
		register_activation_hook(__FILE__, [static::class, 'install'] );
		register_uninstall_hook( __FILE__, [static::class, 'uninstall'] );

		add_action('init', [static::class, 'registerTaskPostType'], 0 );
		add_action('init', [static::class, 'registerTaskStateTaxonomy'], 0 );

		add_action('add_meta_boxes', [static::class, 'addTaskMetabox']);
		add_action('save_post_task',[static::class, 'saveTaskMetaData'],10,3);

		add_filter('map_meta_cap', [static::class, 'filterTasksCapabilitiesForUserRole'],10,4);

		add_action('pre_get_posts',[static::class , 'showOnlyExecutorsTasks'],90,1);

		add_filter('manage_task_posts_columns',[static::class , 'addTaskColumns'],10,1);
		add_action('manage_task_posts_custom_column',[static::class , 'addTaskColumnsData'],10,2);

		add_filter('manage_edit-task_sortable_columns',[static::class, 'makeSortableTaskColumns'],10 ,1);

		add_action('admin_enqueue_scripts', [static::class, 'addStyles']);

	}

	public static function addStyles()
	{
		wp_enqueue_style('tasks-styles',plugin_dir_url(__FILE__).'css/admin.css');
	}

	public static function addTaskColumns($columns)
	{
		$columns['dateTill']='Date Till';

		if(current_user_can('administrator')) {
			$columns['executor'] = 'Executor';
		}

		return $columns;
	}

	public static function addTaskColumnsData($column_name,$post_id)
	{
		if(current_user_can('administrator')){
			if('executor'===$column_name){

				$user=self::getTaskExecutorUser($post_id);
				if($user instanceof WP_User){

					echo '<b>'.$user->display_name.'</b>';
					echo '<div>'.$user->user_email.'</div>';

				}

			}
		}

		if('dateTill'===$column_name){
			$dateTill=new DateTime(get_post_meta($post_id,'dateTill',true));
			$taskStateTerm=self::getCurrentTaskState($post_id);
			if(time()>$dateTill->getTimestamp()){
				$class="dateTill passed";
			}else{
				$class="dateTill future";
			}
			if($taskStateTerm instanceof WP_Term){
				$class.=" ".$taskStateTerm->slug;
			}
			echo '<div class="'.$class.'" > ';
			echo '<div class="human">'.human_time_diff(time(),$dateTill->getTimestamp()).'</div>';
			echo '<div class="datetime">'.$dateTill->format('m/d/Y H:i').'</div>';
			echo '</div> ';
		}

	}

	public static function makeSortableTaskColumns($sortable_columns)
	{
		if(current_user_can('administrator') )
		{
			$sortable_columns['executor']='executorId';
		}

		$sortable_columns['dateTill']='dateTill';

		$sortable_columns['taxonomy-task_state']='task_state';

		return $sortable_columns;
	}

	public static function filterTasksCapabilitiesForUserRole($caps, $cap, $user_id, $args)
	{
		$user=new WP_User($user_id);

		if(strpos($cap,'_task') || strpos($cap,'_tasks')) {

			$post_type = get_post_type_object( 'task' );
			$post=get_post( isset($args[0])?$args[0]:null );

			if( in_array( 'administrator', $user->roles ) ) {
				$caps      = [ 'administrator' ];
			}elseif( in_array( 'editor', $user->roles ) ) {

				switch($cap){
					case 'edit_tasks':{
						$caps=['editor'];
						break;
					}
					case 'edit_others_tasks':
					case 'edit_task':{
						// check meta executorId with current user id maybe!
						if($post instanceof WP_Post){

							if(self::getTaskExecutorId($post->ID)==$user_id){
								$caps=['editor'];
							}
						}

						break;
					}
					case 'delete_task':
					case 'create_task':
					case 'publish_task':{
						$caps=['do_not_allow'];
						break;
					}
				}

			}
		}
		return $caps;
	}


	public static function showOnlyExecutorsTasks(WP_Query $query)
	{
		if($query->query_vars['post_type']==='task'){

			//if( $query->is_main_query() ){

				$user=wp_get_current_user();
				if(current_user_can('administrator')){
					// nothing as all tasks are available to admin
					if( isset($query->query_vars['order']) ){
						$metaQuery=[
								'relation'=>'AND',
						];

						if($query->query_vars['orderby']==='executorId'){
							$metaQuery[]=[
									'key'=>'executorId',
									'value'=>'0',
									'compare'=>'>',
									'type'=>'NUMERIC'
							];
							$query->set('meta_query',$metaQuery);
							$query->set('meta_key','executorId');
							$query->set('orderby','meta_value_num');

						}elseif($query->query_vars['orderby']==='dateTill'){
							$metaQuery[]=[
									'key'=>'dateTill',
									'type'=>'DATETIME'
							];
							$query->set('meta_query',$metaQuery);
							$query->set('meta_key','dateTill');
							$query->set('orderby','meta_value');
						}

					}

				}elseif(current_user_can('editor')){

					// to editor show only assigned to him tasks!
					$metaQuery=[
						'relation'=>'AND',
					];

					$metaQuery[]=[
						'key'=>'executorId',
						'value'=>$user->ID,
						'compare'=>'=',
						'type'=>'NUMERIC'
					];

					if($query->query_vars['orderby']==='dateTill'){
						$metaQuery[]=[
								'key'=>'dateTill',
								'type'=>'DATETIME'
						];
						$query->set('meta_key','dateTill');
						$query->set('orderby','meta_value');
					}

					$query->set('meta_query',$metaQuery);

				}else{
					// show nothing to others!
					$query->set('meta_key','executorId');
					$query->set('meta_value',-1);
				}
		//	}

		}

	}

	public static function addTaskMetabox()
	{
		$taskMetaboxRenderer=current_user_can('administrator')?'adminTaskMetaboxContent':'editorTaskMetaboxContent';
		add_meta_box(
			'task-settings-metabox',
			__( 'Task Settings' ),
			[self::class, $taskMetaboxRenderer],
			'task',
			'advanced',
			'high'
		);
	}


	public static function adminTaskMetaboxContent()
	{
		global $post_id;

		wp_enqueue_script('adminTasksMetabox',plugin_dir_url(__FILE__).'/js/adminMetabox.js', ['jquery','jquery-ui-core','jquery-ui-datepicker'],true);
		wp_enqueue_style('wp-jquery-ui-datepicker',plugin_dir_url(__FILE__).'/css/datepicker.css');


		$dateTill=new DateTime(date('Y').'-'.date('m').'-'.date('d').' 23:59:00'); $dateTill->modify('+1 day'); // default for tomorrow
		$executorId=0;
		if($post_id>0){
			$dateTill=get_post_meta($post_id,'dateTill',true);
			$dateTill=new DateTime($dateTill);
			$executorId=self::getTaskExecutorId($post_id);
		}

		$writers=self::getWriters();

		?>
		<p>
			<label for="executorId">Choose Executor User:</label>
			<select name="executorId" id="executorId" required>
				<?php foreach($writers as $writer){
					$selected = $executorId==$writer->ID?' selected ':'';
					echo '<option value="'.$writer->ID.'" '.$selected.' >'.$writer->display_name.' - '.$writer->user_email.'</option>';
				} ?>
			</select>
		</p>

		<p>
			<label for="dateTill">Complete Till:</label>
			<input  id="dateTill" type="text" name="dateTill" value="<?php echo $dateTill->format('Y-m-d'); ?>" required>
			<label for="hoursTill">Hours</label>
			<input id="hoursTill" type="number" name="hoursTill" min="0" max="23" value="<?php echo $dateTill->format('H'); ?>">
			<label for="minutesTill">Minutes</label>
			<input id="minutesTill" type="number" name="minutesTill" min="0" max="59" value="<?php echo $dateTill->format('i'); ?>">
		</p>
		<?php
		require plugin_dir_path(__FILE__).'partials/admin-task-state-selector.php';


	}

	public static function editorTaskMetaboxContent()
	{
		global $post_id;
		wp_enqueue_script('adminTasksMetabox',plugin_dir_url(__FILE__).'/js/editorMetabox.js', ['jquery','jquery-ui-core'],true);

		$dateTill=new DateTime(self::getTaskDateTill($post_id));
		?>
		<p>
			<label for="dateTill">Complete Till:</label>
			<input  id="dateTill" type="text" value="<?php echo $dateTill->format('m/d/Y'); ?>" readonly>
			<label for="hoursTill">Hours</label>
			<input id="hoursTill" type="number" readonly value="<?php echo $dateTill->format('H'); ?>">
			<label for="minutesTill">Minutes</label>
			<input id="minutesTill" type="number" readonly value="<?php echo $dateTill->format('i'); ?>">
		</p>

		<?php

		require plugin_dir_path(__FILE__).'partials/admin-task-state-selector.php';

	}

	public static function saveTaskMetaData($post_id, $post, $update)
	{
		self::handleTaskStateChange($post_id,$post,$update);

		if(current_user_can('administrator')){

			if(isset($_POST['dateTill'])){
				$oldDateTill=get_post_meta($post_id,'dateTill',true);

				$dateTill=new DateTime( sanitize_text_field($_POST['dateTill']).' '.intval($_POST['hoursTill']).':'.intval($_POST['minutesTill']) );
				$dateTill=$dateTill->format('Y-m-d H:i:s');

				update_post_meta($post_id,'dateTill',$dateTill);
			}
			if(isset($_POST['executorId'])){

				//$oldExecutorId=intval(get_post_meta($post_id,'executorId',true));
				$executorId=intval($_POST['executorId']);
				// check that this new executor is valid user
				if($executorId>0){
					$writer=new WP_User($executorId);
					if($writer instanceof WP_User && $writer->exists() && in_array('editor',$writer->roles)){
						// update meta!
						update_post_meta($post_id,'executorId',$executorId);
						// also set author id to  editor so he could edit it but not other editor

					}
				}

			}

		}
		// we store comments\descriptions for each state in handleTaskStateChange
	}

	protected static function handleTaskStateChange($post_id,$post,$update)
	{
		if(current_user_can('administrator') || current_user_can('editor'))
		{
			if(isset($_POST['taskState'])){

				$taskStateTermSlug=sanitize_key($_POST['taskState']);

				if($taskStateTermSlug){
					// save it
					$taskStateTerm=get_term_by('slug',$taskStateTermSlug,'task_state');
					if($taskStateTerm instanceof WP_Term)
					{
						wp_set_post_terms($post_id, $taskStateTerm->slug ,'task_state');
						// lets store description provided!
						$editorId=$taskStateTerm->slug.'_description';
						// store each switch state too.
						$switchedToStateTime='switchedTo_'.$taskStateTerm->slug;

						$description=false;
						if($taskStateTerm->slug=='todo'){
							if(current_user_can('administrator')){
								// allow to save initial todo only for administrator
								$description=isset($_POST[$editorId])?wp_kses_post($_POST[$editorId]):'';
							}
						}else{
							// allow to save all types of descriptions for both admin and editor
							$description=isset($_POST[$editorId]) ? wp_kses_post($_POST[$editorId]):'';
						}

						if( false !== $description ){
							update_post_meta($post_id, $editorId,$description);
							update_post_meta($post_id, $switchedToStateTime,  date('Y-m-d H:i:s'));
							update_post_meta($post_id,'lastChangedStateTime', date('Y-m-d H:i:s'));

							// TODO do some action on update for example send email notifications
							// make it handled by other plugins too
							do_action('assignments_task_state_changed',[ $post_id, $taskStateTerm, $description]);

						}


					}
				}

			}
		}

		if(false===$update){
			// it's a new task! so set it to Todo state by default
			wp_set_post_terms($post_id,'todo','task_state');
		}
	}



	public static function registerTaskPostType()
	{

		$labels = array(
			'name'                  => _x( 'Tasks', 'Post Type General Name', 'text_domain' ),
			'singular_name'         => _x( 'Task', 'Post Type Singular Name', 'text_domain' ),
			'menu_name'             => __( 'Tasks', 'text_domain' ),
			'name_admin_bar'        => __( 'Task', 'text_domain' ),
			'archives'              => __( 'Task Archives', 'text_domain' ),
			'attributes'            => __( 'Task Attributes', 'text_domain' ),
			'parent_item_colon'     => __( 'Parent Item:', 'text_domain' ),
			'all_items'             => __( 'All Tasks', 'text_domain' ),
			'add_new_item'          => __( 'Add New Task', 'text_domain' ),
			'add_new'               => __( 'Add New', 'text_domain' ),
			'new_item'              => __( 'New Task', 'text_domain' ),
			'edit_item'             => __( 'Edit Task', 'text_domain' ),
			'update_item'           => __( 'Update Task', 'text_domain' ),
			'view_item'             => __( 'View Task', 'text_domain' ),
			'view_items'            => __( 'View Tasks', 'text_domain' ),
			'search_items'          => __( 'Search Task', 'text_domain' ),
			'not_found'             => __( 'Not found', 'text_domain' ),
			'not_found_in_trash'    => __( 'Not found in Trash', 'text_domain' ),
			'featured_image'        => __( 'Featured Image', 'text_domain' ),
			'set_featured_image'    => __( 'Set featured image', 'text_domain' ),
			'remove_featured_image' => __( 'Remove featured image', 'text_domain' ),
			'use_featured_image'    => __( 'Use as featured image', 'text_domain' ),
			'insert_into_item'      => __( 'Insert into Task', 'text_domain' ),
			'uploaded_to_this_item' => __( 'Uploaded to this item', 'text_domain' ),
			'items_list'            => __( 'Task list', 'text_domain' ),
			'items_list_navigation' => __( 'Task list navigation', 'text_domain' ),
			'filter_items_list'     => __( 'Filter task list', 'text_domain' ),
		);

		$args = array(
			'label'                 => __( 'Task', 'text_domain' ),
			'description'           => __( 'Task to be assigned to specific user', 'text_domain' ),
			'labels'                => $labels,
			'supports'              => array( 'title', 'revisions' ),//editor, 'custom-fields',
			'taxonomies'            => array( 'task_state' ),
			'hierarchical'          => false,
			'public'                => true,
			'show_ui'               => true,
			'show_in_menu'          => true,
			'menu_position'         => 5,
			'menu_icon'             => 'dashicons-flag',
			'show_in_admin_bar'     => true,
			'show_in_nav_menus'     => true,
			'can_export'            => true,
			'has_archive'           => true,
			'exclude_from_search'   => true,
			'publicly_queryable'    => true,
			'capability_type'       =>array('task','tasks'),
			'show_in_rest'          => false,
		);

		if(!current_user_can('administrator'))
		{
			$args['capabilities']=[
					'create_posts'=>'do_not_allow'
			];
		}
		register_post_type( 'task', $args );

	}

	public static function registerTaskStateTaxonomy() {

		$labels = array(
			'name'                       => _x( 'Task state', 'Taxonomy General Name', 'text_domain' ),
			'singular_name'              => _x( 'Task state', 'Taxonomy Singular Name', 'text_domain' ),
			'menu_name'                  => __( 'Task state', 'text_domain' ),
			'all_items'                  => __( 'All states', 'text_domain' ),
			'parent_item'                => __( 'Parent state', 'text_domain' ),
			'parent_item_colon'          => __( 'Parent state:', 'text_domain' ),
			'new_item_name'              => __( 'New Task state', 'text_domain' ),
			'add_new_item'               => __( 'Add New Task state', 'text_domain' ),
			'edit_item'                  => __( 'Edit State', 'text_domain' ),
			'update_item'                => __( 'Update State', 'text_domain' ),
			'view_item'                  => __( 'View State', 'text_domain' ),
			'separate_items_with_commas' => __( 'Separate states with commas', 'text_domain' ),
			'add_or_remove_items'        => __( 'Add or remove states', 'text_domain' ),
			'choose_from_most_used'      => __( 'Choose from the most used', 'text_domain' ),
			'popular_items'              => __( 'Popular states', 'text_domain' ),
			'search_items'               => __( 'Search States', 'text_domain' ),
			'not_found'                  => __( 'Not Found', 'text_domain' ),
			'no_terms'                   => __( 'No States', 'text_domain' ),
			'items_list'                 => __( 'States list', 'text_domain' ),
			'items_list_navigation'      => __( 'Items list navigation', 'text_domain' ),
		);
		$rewrite = array(
			'slug'                       => 'state',
			'with_front'                 => true,
			'hierarchical'               => false,
		);
		$args = array(
			'labels'                     => $labels,
			'hierarchical'               => false,
			'public'                     => true,
			'show_ui'                    => false, // true for debugging
			'show_admin_column'          => true,
			'show_in_nav_menus'          => false,
			'show_tagcloud'              => false,
			'query_var'                  => 'task_state',
			'rewrite'                    => $rewrite,
			'show_in_rest'               => false,
		);
		register_taxonomy( 'task_state', array( 'task' ), $args );

	}

	/**
	 * @param $post_id
	 *
	 * @return WP_Term|null
	 */
	public static function getCurrentTaskState($post_id)
	{
		$currentTaskStates=wp_get_object_terms($post_id,'task_state');

		if(is_wp_error($currentTaskStates))return null;

		return $currentTaskStates[0];
	}

	public static function getTasksStates()
	{
		return get_terms([
				'taxonomy'=>'task_state',
				'hide_empty'=>false,
				'order'=>'DESC'
		]);
	}


	public static function getTaskDescriptionByState($post_id,$term_state_slug)
	{
		$description_field=$term_state_slug.'_description';
		return get_post_meta($post_id,$description_field,true);
	}

	public static function getTaskStateChangedToStateDateTime($post_id, $term_state_slug)
	{
		$switchedToStateTimeField='switchedTo_'.$term_state_slug;
		return get_post_meta($post_id,$switchedToStateTimeField,true);
	}



	public static function getTaskDataForCurrentState($post_id)
	{
		$currentTaskState=self::getCurrentTaskState($post_id);
		return self::getStateTaskData($post_id,$currentTaskState);
	}

	public static function getTaskStatesData($post_id)
	{
		$taskStatesData=[];
		$taskStates=self::getTasksStates();
		foreach($taskStates as $taskStateTerm)
		{
			$data=self::getStateTaskData($post_id,$taskStateTerm);
			if($data['description'] || $data['time']){
				$taskStatesData[]=$data;
			}

		}
		return $taskStatesData;

	}

	public static function getStateTaskData($post_id,$taskStateTerm)
	{
		$description='';
		$timeChanged='';

		if($taskStateTerm instanceof WP_Term){
			$timeChanged=self::getTaskStateChangedToStateDateTime($post_id,$taskStateTerm->slug);
			$description=self::getTaskDescriptionByState($post_id,$taskStateTerm->slug);

		}
		return [
				'description'=>$description,
				'time'=>$timeChanged,
				'state'=>$taskStateTerm
		];


	}

	public static function getTaskDateTill($post_id)
	{
		return get_post_meta($post_id,'dateTill',true);
	}


	protected static function getWriters()
	{
		if( count(self::$writers) ){return self::$writers; }

		self::$writers=get_users( [ 'role'=>'editor'] );

		return self::$writers;
	}


	/**
	 * @param $user_id int
	 *
	 * @return null|WP_User
	 */
	protected static function getWriterById($user_id)
	{
		$writers=self::getWriters();
		foreach($writers as $writer)
		{
			if($writer->ID == $user_id)
				return $writer;
		}
		return null;
	}

	protected static function getTaskExecutorId($post_id)
	{
		return intval(get_post_meta($post_id,'executorId',true));
	}

	public static function getTaskExecutorUser($post_id)
	{
		return get_user_by('ID', self::getTaskExecutorId($post_id) );
	}


	public static function install()
	{
		// lets create task states
		static::registerTaskStateTaxonomy();

		$taskStates=[
			[
				'name'=>'Todo',
				'slug'=>'todo',
				'description'=>'This task is in todo, the earliest state'
			],
			[
				'name'=>'In Progress',
				'slug'=>'inprogress',
				'description'=>'Task marked as in progress'
			],
			[
				'name'=>'Completed',
				'slug'=>'completed',
				'description'=>'Completed task'
			],
			[
				'name'=>'Canceled',
				'slug'=>'canceled',
				'description'=>'Task marked as canceled'
			],
			[
				'name'=>'Delayed',
				'slug'=>'delayed',
				'description'=>'Task marked as delayed'
			]
		];
		// check if they are exist and create if not
		foreach($taskStates as $taskState)
		{
			$term=get_term_by('slug',$taskState['slug'],'task_state');

			if(false===$term){
				// create one!
				$term=wp_insert_term($taskState['name'],'task_state',
					[
						'slug'=>$taskState['slug'],
						'description'=>$taskState['description']
					]
				);
				if(is_wp_error($term)){
					// make installation failed
					throw new Exception($term->get_error_message());
				}
			}
		}

	}

	public static function uninstall()
	{
	}


	public static function getInstance()
	{
		if(is_null(static::$instance) ){
			static::$instance= new static();
		}
		return static::$instance;
	}

	private function __clone()
	{
	}

	private function __wakeup()
	{
	}
}

$GLOBALS['Assignments']=Assignments::getInstance();