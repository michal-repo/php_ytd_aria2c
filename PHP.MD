
`sudo wget -qO /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg`
`echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/php.list`
`sudo apt update`
`sudo apt install php8.2 php8.2-cli php8.2-common php8.2-curl php8.2-fpm php8.2-intl php8.2-mbstring php8.2-mysql php8.2-opcache php8.2-readline php8.2-sqlite3 php8.2-xml`
