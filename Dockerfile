FROM composer:latest

ENV APP_HOME /usr/src/sonoff

COPY . $APP_HOME

#RUN rm $APPHOME/Dockerfile
#RUN rm $APPHOME/docker-compose.yml
RUN docker-php-ext-install pcntl

RUN chown -R www-data:www-data $APP_HOME

WORKDIR $APP_HOME

RUN composer require workerman/workerman workerman/channel

USER www-data

CMD [ "php", "./sonoffServer.php", "start"]

EXPOSE 2443 2333

