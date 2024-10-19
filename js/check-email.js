jQuery(document).ready(function($) {
    console.log('jQuery loaded.');
    $('#check_email').click(function() {
        var email = $('#email_siswa').val();
        console.log('Button clicked. Email:', email); // Debugging line

        if (email) {
            $.ajax({
                type: 'POST',
                url: ajax_object.ajax_url,
                data: {
                    action: 'check_email',
                    email: email
                },
                success: function(response) {
                    console.log('Response received:', response); // Debugging line

                    if (response.success) {
                        $('#email_check_result').html('<span style="color: green;">' + response.data + '</span>');
                    } else {
                        $('#email_check_result').html('<span style="color: red;">' + response.data + '</span>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error); // Debugging line
                }
            });
        } else {
            alert('Please enter an email address.');
        }
    });
});