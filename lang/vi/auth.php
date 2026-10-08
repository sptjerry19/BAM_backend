<?php

return [
    'validation' => [
        'name_required' => 'Vui lòng nhập họ tên.',
        'name_string' => 'Họ tên phải là chuỗi ký tự.',
        'name_max' => 'Họ tên không được vượt quá :max ký tự.',
        'email_required' => 'Vui lòng nhập email.',
        'email_email' => 'Email không đúng định dạng.',
        'email_unique' => 'Email này đã được sử dụng.',
        'password_required' => 'Vui lòng nhập mật khẩu.',
        'password_string' => 'Mật khẩu phải là chuỗi ký tự.',
        'password_min' => 'Mật khẩu phải có ít nhất :min ký tự.',
        'password_confirmed' => 'Xác nhận mật khẩu không khớp.',
    ],

    'login_success' => 'Đăng nhập thành công.',
    'login_failed' => 'Email hoặc mật khẩu không đúng.',
    'account_hidden' => 'Tài khoản này đã bị ẩn.',
    'register_success' => 'Tạo tài khoản thành công.',
    'logout_success' => 'Đăng xuất thành công.',
    'unauthorized' => 'Chưa xác thực.',
    'forbidden' => 'Bạn không có quyền thực hiện thao tác này.',
    'email_not_found' => 'Không tìm thấy tài khoản với email này.',
    'forgot_success' => 'Mã đặt lại mật khẩu đã được tạo.',
    'reset_success' => 'Đặt lại mật khẩu thành công.',
    'reset_invalid' => 'Mã đặt lại mật khẩu không hợp lệ.',
    'reset_expired' => 'Mã đặt lại mật khẩu đã hết hạn.',
];
