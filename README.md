# Gopinath_Mobile Website

## Folder Structure (client aur admin alag)

```
gopinath/
├── client/              ← Public website (jo customer dekhte hain)
│   ├── index.html
│   ├── css/style.css
│   └── js/script.js     ← PHP API se data fetch karta hai
│
├── admin/                ← Dashboard (sirf shop owner ke liye)
│   ├── index.html
│   ├── css/admin.css
│   └── js/admin.js       ← Login + Save + Upload, PHP API use karta hai
│
├── api/                  ← PHP backend (dono client & admin isko use karte hain)
│   ├── config.php
│   ├── login.php
│   ├── logout.php
│   ├── check_session.php
│   ├── get_data.php      ← Public: website ka saara content deta hai
│   ├── save_data.php     ← Protected: dashboard se save hota hai
│   ├── reset_data.php    ← Protected: default content par reset
│   ├── upload.php        ← Protected: image upload handle karta hai
│   └── data.json         ← (auto-create hoga) — sara content yahin store hota hai
│
└── uploads/               ← Dashboard se upload ki gayi images yahan save hoti hain
```

## Kaise Chalayein (Setup)

1. Is poore `gopinath` folder ko apne PHP server me daalo:
   - **XAMPP/WAMP**: `htdocs/gopinath/` me copy karo
   - Koi bhi hosting jo PHP support kare (cPanel, Hostinger, etc.)
2. PHP/Apache start karo.
3. Website (client) kholo: `http://localhost/gopinath/client/index.html`
4. Dashboard (admin) kholo: `http://localhost/gopinath/admin/index.html`

**Important:** Yeh website ab sirf PHP server par hi chalegi (double-click se file kholne par AJAX/PHP kaam nahi karega).

## Dashboard Login

```
Username: admin
Password: admin123
```

Password badalne ke liye terminal me ye command chalao (PHP installed honi chahiye):
```
php -r "echo password_hash('yourNewPassword', PASSWORD_DEFAULT);"
```
Jo hash output mile usse `api/config.php` me paste karo:
```php
define('ADMIN_PASS_HASH', 'PASTE_HASH_HERE');
```

## Security Features

- Password **hashed** (bcrypt) — kabhi plain text store nahi hota
- Login pe **rate limiting**: 5 galat attempts ke baad 5 minute ka lockout (per IP)
- Session cookie `HttpOnly` + `SameSite=Lax`, aur HTTPS pe `Secure` flag
- **CSRF token**: dashboard se har save/upload/reset request token verify karta hai
- Image upload: file extension nahi, **actual file content (MIME type)** check hota hai — fake-extension files reject ho jaati hain
- `uploads/` folder me koi bhi PHP/script file execute nahi ho sakti (`.htaccess` se block)
- `api/data.json` aur attempt-log files browser se directly open nahi ho sakti
- Website pe dikhne wala saara dynamic content (product names, descriptions, captions) HTML-escape hota hai — stored XSS se bachne ke liye

**Note:** `.htaccess` rules sirf Apache server pe kaam karti hain. Agar Nginx use kar rahe ho, equivalent rules apne server config me add karni hongi (humse pooch sakte ho).

## Dashboard se Kya Control Hota Hai

- Top Bar: phone, email, Instagram, Facebook, LinkedIn
- Hero banner ka text + About Us text
- Services list (add/edit/delete)
- Products list (image upload + name + description)
- Accessories list (image upload + name)
- Gallery images (image upload + caption)
- Why Choose Us list (add/edit/delete)
- Contact info: address, phone, email, Google Map embed link
- "Reset to Default" button — sab kuch original content par wapas

Sara data `api/data.json` file me save hota hai — koi database setup nahi chahiye.

## Image Upload

Dashboard me kisi bhi product/accessory/gallery item ke liye "Choose File" se direct image upload kar sakte ho — woh `uploads/` folder me save ho jayegi aur image URL field automatically fill ho jayega. Chaaho to URL field me direct kisi bhi image ka link bhi paste kar sakte ho.

## Permissions (agar Linux hosting hai)

```
chmod 755 api/
chmod 755 uploads/
chmod 666 api/data.json   (file ban jaane ke baad)
```
