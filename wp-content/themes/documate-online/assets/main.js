jQuery(document).ready(function ($) {


    $('#authenticate-btn').on('click', function () {
        console.log('54655464')
        // console.log($('#pdf_file_input').files[0]);
        // var pdf_file_input = document.getElementById('pdf_file_input');
        // console.log(pdf_file_input.files[0]);
        // console.log(pdf_file_input.files);
        // var name_pdf = pdf_file_input.files[0].name;
        // var file_data = jQuery('#pdf_file_input').prop('files')[0];
        //
        var form_data = new FormData();
        // form_data.append('file', file_data);
        form_data.append('action', 'authenticate_test');
        // console.log(form_data);
        jQuery.ajax({
            url: ajaxurl,
            type: 'post',
            contentType: false,
            processData: false,
            data: form_data,

        }).done(function (response) {
            console.log(response);
            document.location.href = 'https://test.id.gov.ua/?response_type=code&%20client_id=e4fb44cced44cc211c4451ad3b4238f3&auth_type=dig_sign%26&state=xyz1234567890&redirect_uri=https%3A%2F%2Fdocumate.online%2Fmy-account%2F'


        })
    })

    $('#create_pdf').on('click', function () {
        // console.log($('#pdf_file_input').files[0]);
        var pdf_file_input = document.getElementById('pdf_file_input');
        console.log(pdf_file_input.files[0]);
        console.log(pdf_file_input.files);
        var name_pdf = pdf_file_input.files[0].name;
        var file_data = jQuery('#pdf_file_input').prop('files')[0];

        var form_data = new FormData();
        form_data.append('file', file_data);
        form_data.append('action', 'send_pdf');
        console.log(form_data);
        jQuery.ajax({
            url: ajaxurl,
            type: 'post',
            contentType: false,
            processData: false,
            data: form_data,

        }).done(function (response) {
            console.log(response);
        })
    })


    $(".wrapper .tab").click(function() {
        $(".wrapper .tab").removeClass("active").eq($(this).index()).addClass("active");
        $(".tab_item").hide().eq($(this).index()).fadeIn()
    }).eq(0).addClass("active");

});