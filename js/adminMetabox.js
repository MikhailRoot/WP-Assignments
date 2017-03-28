jQuery(document).ready(function(){
    // lets make it datepicker
    jQuery('#dateTill').datepicker({dateFormat:'yy-mm-dd'});

    jQuery('#taskState').on('change',function taskStateChanged(){
        var value=this.value;
        jQuery('.description:not(.'+value+')').addClass('hidden');
        jQuery('.description.'+value).removeClass('hidden');
    });

});