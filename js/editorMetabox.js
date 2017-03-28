jQuery(document).ready(function(){

    jQuery('input[name="post_title"]').attr('readonly','readonly');

    jQuery('#taskState').on('change',function taskStateChanged(){
        var value=this.value;
        jQuery('.description:not(.'+value+')').addClass('hidden');
        jQuery('.description.'+value).removeClass('hidden');
    });
});