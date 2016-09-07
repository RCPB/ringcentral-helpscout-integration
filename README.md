# RingCentral &rarr; HelpScout Integration

## Summary
This script recieve information form RingCentral Calllog and create new tasks in HelpScout account.

## Installation
You should have [Composer](https://getcomposer.org/) installed.

```
git clone https://github.com/RCPB/ringcentral-helpscout-integration.git rc
cd rc/api
php composer.phar update
```

## Integration
1. Create your own copy of `engine/_credentials.json` file. 
2. Get an API keys from RingCentral and HelpScout and put them in it.
3. Run `php ./index.php`

## Copyrights
&copy; 2016, jfkz

License: [MIT](https://jfkz.mit-license.org/)