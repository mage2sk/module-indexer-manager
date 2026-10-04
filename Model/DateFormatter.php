<?php
declare(strict_types=1);

namespace Panth\IndexerManager\Model;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

class DateFormatter
{
    public function __construct(private readonly TimezoneInterface $timezone)
    {
    }

    public function format(?string $gmtDate): string
    {
        $gmtDate = trim((string)$gmtDate);
        if ($gmtDate === '' || str_starts_with($gmtDate, '0000-00-00')) {
            return '';
        }
        try {
            $date = new \DateTime($gmtDate, new \DateTimeZone('UTC'));
            return (string)$this->timezone->formatDateTime(
                $date,
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::MEDIUM
            );
        } catch (\Throwable) {
            return $gmtDate;
        }
    }
}
