<?php
declare(strict_types=1);


// useful when script is being executed by cron user
$pathPrefix = ''; // e.g. /usr/share/nginx/oci-arm-host-capacity/

require "{$pathPrefix}vendor/autoload.php";

use Dotenv\Dotenv;
use Hitrov\Exception\ApiCallException;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;
use Hitrov\TooManyRequestsWaiter;

$envFilename = empty($argv[1]) ? '.env' : $argv[1];
$dotenv = Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
$dotenv->safeLoad();

/*
 * No need to modify any value in this file anymore!
 * Copy .env.example to .env and adjust there instead.
 *
 * README.md now has all the information.
 */
$config = new OciConfig(
    (string) (getenv('OCI_REGION') ?: ''),
    (string) (getenv('OCI_USER_ID') ?: ''),
    (string) (getenv('OCI_TENANCY_ID') ?: ''),
    (string) (getenv('OCI_KEY_FINGERPRINT') ?: ''),
    (string) (getenv('OCI_PRIVATE_KEY_FILENAME') ?: ''),
    getenv('OCI_AVAILABILITY_DOMAIN') ?: null, // null or '' or 'jYtI:PHX-AD-1' or ['jYtI:PHX-AD-1','jYtI:PHX-AD-2']
    (string) (getenv('OCI_SUBNET_ID') ?: ''),
    (string) (getenv('OCI_IMAGE_ID') ?: ''),
    (int) (getenv('OCI_OCPUS') ?: 4),
    (int) (getenv('OCI_MEMORY_IN_GBS') ?: 24)
);

$bootVolumeSizeInGBs = (string) (getenv('OCI_BOOT_VOLUME_SIZE_IN_GBS') ?: '');
$bootVolumeId = (string) (getenv('OCI_BOOT_VOLUME_ID') ?: '');
if ($bootVolumeSizeInGBs) {
    $config->setBootVolumeSizeInGBs($bootVolumeSizeInGBs);
} elseif ($bootVolumeId) {
    $config->setBootVolumeId($bootVolumeId);
}

$api = new OciApi();
if (getenv('CACHE_AVAILABILITY_DOMAINS')) {
    $api->setCache(new FileCache($config));
}
if (getenv('TOO_MANY_REQUESTS_TIME_WAIT')) {
    $api->setWaiter(new TooManyRequestsWaiter((int) getenv('TOO_MANY_REQUESTS_TIME_WAIT')));
}
$notifier = (function (): \Hitrov\Interfaces\NotifierInterface {
    /*
     * if you have own https://core.telegram.org/bots
     * and set TELEGRAM_BOT_API_KEY and your TELEGRAM_USER_ID in .env
     *
     * then you can get notified when script will succeed.
     * otherwise - don't mind OR develop you own NotifierInterface
     * to e.g. send SMS or email.
     */
    return new \Hitrov\Notification\Telegram();
})();

$shape = (string) (getenv('OCI_SHAPE') ?: 'VM.Standard.A1.Flex');

$maxRunningInstancesOfThatShape = 1;
if (getenv('OCI_MAX_INSTANCES') !== false) {
    $maxRunningInstancesOfThatShape = (int) getenv('OCI_MAX_INSTANCES');
}

try {
    $instances = $api->getInstances($config);

    $existingInstances = $api->checkExistingInstances($config, $instances, $shape, $maxRunningInstancesOfThatShape);
    if ($existingInstances) {
        echo "$existingInstances\n";
        return;
    }

    if (!empty($config->availabilityDomains)) {
        if (is_array($config->availabilityDomains)) {
            $availabilityDomains = $config->availabilityDomains;
        } else {
            $availabilityDomains = [ $config->availabilityDomains ];
        }
    } else {
        $availabilityDomains = $api->getAvailabilityDomains($config);
    }

    foreach ($availabilityDomains as $availabilityDomainEntity) {
        $availabilityDomain = is_array($availabilityDomainEntity) ? $availabilityDomainEntity['name'] : $availabilityDomainEntity;
        try {
            $instanceDetails = $api->createInstance($config, $shape, (string) (getenv('OCI_SSH_PUBLIC_KEY') ?: ''), $availabilityDomain);
        } catch (\Hitrov\Exception\TooManyRequestsWaiterException $e) {
            echo $e->getMessage() . "\n";
            echo "Sleeping for 60 seconds to prevent hot loop...\n";
            sleep(60);
            return;
        } catch(ApiCallException $e) {
            $message = $e->getMessage();
            echo "$message\n";

            if (
                $e->getCode() === 500 &&
                strpos($message, 'InternalError') !== false &&
                strpos($message, 'Out of host capacity') !== false
            ) {
                // trying next availability domain
                sleep(16);
                continue;
            }

            // current config is broken
            echo "Config error occurred. Sleeping for 60 seconds...\n";
            sleep(60);
            return;
        }

        // success
        $message = json_encode($instanceDetails, JSON_PRETTY_PRINT);
        echo "$message\n";
        if ($notifier->isSupported()) {
            $notifier->notify($message);
        }

        return;
    }

    // Loop finished without success, sleep 60 seconds to prevent hot loop
    echo "All availability domains checked. Sleeping for 60 seconds before next try...\n";
    sleep(60);

} catch (\Hitrov\Exception\TooManyRequestsWaiterException $e) {
    echo $e->getMessage() . "\n";
    echo "Sleeping for 60 seconds to prevent hot loop...\n";
    sleep(60);
} catch (ApiCallException $e) {
    echo $e->getMessage() . "\n";
    echo "API call failed. Sleeping for 60 seconds...\n";
    sleep(60);
} catch (\Throwable $e) {
    echo "An unexpected error occurred: " . $e->getMessage() . "\n";
    echo "Sleeping for 60 seconds...\n";
    sleep(60);
}
