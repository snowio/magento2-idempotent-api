#Magento 2 Idempotent API
## Description
Module that ensures all API operations are *idempotent*. 
> **Idempotence** : From a RESTful service standpoint, for an operation (or service call) to be idempotent, clients can make that same call repeatedly while producing the same result. In other words, making multiple identical requests has the same effect as making a single request. Note that while idempotent operations produce the same result on the server (no side effects), the response itself may not be the same (e.g. a resource's state may change between requests).
>
> --<cite>RestApiTutorial.com http://www.restapitutorial.com/lessons/idempotency.html</cite>

## Prerequisites
* PHP 7.0 or newer
* Composer  (https://getcomposer.org/download/).
* `magento/framework` 100 or newer
* `snowio/magento2-lock` version 1 or newer.

## Installation
```
composer require snowio/magento2-idempotent-api
php bin/magento setup:upgrade
php bin/magento module:enable SnowIO_IdempotentAPI
php bin/magento setup:di:compile
php bin/magento cache:flush
```

## Usage
###Request Headers
####X-Message-Group-ID
The `X-Message-Group-ID` request-header field specifies the message group of the request. If no `X-Message-Group-ID` is specified then the request will not be treated idempotently.

####X-Message-Timestamp
The `X-Message-Timestamp` request-header field corresponds to the time the message was created. If this
field is not specified then this plugin uses `\time()` as the message timestamp, please note that the this is a **Unix Timestamp**.

###Error Response Codes

#####409 Conflict
This error occurs when 2 or more requests with the same `X-Message-Group-ID` are processed at the simultaneously one of the requests will result in 
a  *409 CONFLICT* error response. The Idempotent API should ensure that the resource is not simultaneously updated.

#####412 Precondition Failed
This error occurs when a *late message* is received. Message **A** is a *late message* 
**iff** message **B** (which has the same `X-Message-Group-ID`) was created in the source client after **A** (has a larger `X-Message-Timestamp` than message **A**) 
and was received **before** message **A**. Message **A** will try update the resource but fails as message **B** is more recent than message **A**. 
This ensures that **late messages**  do not affect the state of the resource.


## License
This software is licensed under the MIT License. [View the license](LICENSE)