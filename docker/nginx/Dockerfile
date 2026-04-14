FROM nginx:1.27-alpine@sha256:65645c7bb6a0661892a8b03b89d0743208a18dd2f3f17a54ef4b76fb8e2f2a10

RUN apk add --no-cache openssl \
    && mkdir -p /etc/nginx/ssl \
    && openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
         -keyout /etc/nginx/ssl/nginx.key \
         -out    /etc/nginx/ssl/nginx.crt \
         -subj   "/C=RU/ST=Moscow/L=Moscow/O=Blog/CN=contextbot.ru"
