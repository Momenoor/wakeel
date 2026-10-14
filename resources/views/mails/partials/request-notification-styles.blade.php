* {
    font-family: 'Boutros MBC Dinkum', sans-serif !important;
}

body {
    background: #f4f4f7;
    margin: 0;
    padding: 0;
    color: #333;
    direction: {{ app()->getLocale() == 'ar' ? 'rtl' : 'ltr' }};
    text-align: {{ app()->getLocale() == 'ar' ? 'right' : 'left' }};
}

.wrapper {
    max-width: 600px;
    margin: 40px auto;
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.header {
    background: #1B3A5C;
    padding: 30px 40px;
    text-align: center;
}

.header h1 {
    color: #fff;
    font-size: 20px;
    margin: 10px 0 0;
}

.body {
    padding: 32px 40px;
}

.body p {
    font-size: 14px;
    line-height: 1.7;
    margin: 0 0 16px;
}

.info-box {
    background: #f0f5fb;
    border- {{ app()->getLocale() == 'ar' ? 'right' : 'left' }}: 4px solid #1B3A5C;
    border-radius: 4px;
    padding: 16px 20px;
    margin: 20px 0;
}

.info-box table {
    width: 100%;
    border-collapse: collapse;
}

.info-box td {
    padding: 8px 0;
    font-size: 14px;
}

.info-box td:first-child {
    color: #666;
    width: 35%;
}

.info-box td:last-child {
    font-weight: bold;
    color: #1B3A5C;
}

.actions {
    text-align: center;
    margin: 32px 0 16px;
}

.btn {
    display: inline-block;
    padding: 12px 28px;
    border-radius: 6px;
    font-size: 15px;
    font-weight: bold;
    text-decoration: none;
    background: #1B3A5C;
    color: #ffffff !important;
}

.footer {
    background: #f4f4f7;
    padding: 20px 40px;
    text-align: center;
    font-size: 12px;
    color: #888;
}
