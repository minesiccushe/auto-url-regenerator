jQuery(document).ready(function($) {
    $('a.nav-tab').click(function() {
        var index = $('a.nav-tab').index(this);
        var tabContents  = $('.tab_contents');
        var navClassName = 'nav-tab-active';
        var tabClassName = 'tab_contents-active'
        $('a.nav-tab').removeClass(navClassName);
        $(this).addClass(navClassName);
        tabContents.removeClass(tabClassName).eq(index).addClass(tabClassName);
    });
    $('.row_interval_kind').each( function(index, element){
        var className = 'disable';
        var kind_val = $(this).find('select').val();
        $(this).parent().find('tr.row_interval').addClass(className).eq(+kind_val+1).removeClass(className);
        $(this).removeClass(className);
    });
    $('.row_interval_kind select').change(function(){
        var className = 'disable';
        var kind_val = $(this).val();
        $(this).closest('tbody.table_interval').find('tr.row_interval').addClass(className).eq(+kind_val+1).removeClass(className);
        $(this).closest('tr.row_interval_kind').removeClass(className);
    });
});