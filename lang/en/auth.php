<?php

return [
    'validation' => [
        'name_required' => 'The name field is required.',
        'name_string' => 'The name must be a string.',
        'name_max' => 'The name may not be greater than :max characters.',
        'email_required' => 'The email field is required.',
        'email_email' => 'The email must be a valid email address.',
        'email_unique' => 'The email has already been taken.',
        'password_required' => 'The password field is required.',
        'password_string' => 'The password must be a string.',
        'password_min' => 'The password must be at least :min characters long.',
        'password_confirmed' => 'The password confirmation does not match.',
    ],

    'login_success' => 'Logged in successfully.',
    'login_failed' => 'Invalid email or password.',
    'account_hidden' => 'This account has been hidden.',
    'register_success' => 'Account created successfully.',
    'logout_success' => 'Logged out successfully.',
    'unauthorized' => 'Unauthorized.',
    'forbidden' => 'You do not have permission to perform this action.',
    'email_not_found' => 'No account found with this email.',
    'forgot_success' => 'Password reset code has been created.',
    'reset_success' => 'Password has been reset successfully.',
    'reset_invalid' => 'Invalid password reset token.',
    'reset_expired' => 'Password reset token has expired.',
];
