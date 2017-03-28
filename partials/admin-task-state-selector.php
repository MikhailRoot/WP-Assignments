<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

global $post_id;

$task_states=Assignments::getTasksStates();

$currentTask=get_post($post_id);
$currentTaskStates=wp_get_object_terms($currentTask->ID,'task_state');


?>
<p>
	<label for="taskState">Change State of task:</label>
	<select name="taskState" id="taskState">
		<?php foreach($task_states as $task_state){
			$selected = $task_state->term_id==$currentTaskStates[0]->term_id?' selected ':'';
			echo '<option value="'.$task_state->slug.'" '.$selected.' >'.$task_state->name.'</option>';
		} ?>
	</select>
</p>
<div class="descriptions">
	<?php foreach($task_states as $task_state){
		$classForSection=' '.$task_state->slug.' ';

		if($task_state->term_id != $currentTaskStates[0]->term_id){
			$classForSection.=' hidden ';
		}

		$editorId=$task_state->slug.'_description';
		$content=get_post_meta($post_id,$editorId,true);

	    echo '<div class="description'.$classForSection.' ">';
		echo '<label for="'.$editorId.'">'.$task_state->name.' - details</label>';
		wp_editor($content,$editorId,array('textarea_name'=>$editorId));
		echo '</div>';
	}
	?>
</div>