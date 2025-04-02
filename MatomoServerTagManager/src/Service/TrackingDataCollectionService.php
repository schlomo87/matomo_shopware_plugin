<?php declare(strict_types=1);

namespace SwClp\MatomoServerTagManager\Service;
use Cocur\Slugify\SlugifyInterface;

class TrackingDataCollectionService
{
    private ?array $trackingData = null;
    private SlugifyInterface $slugify;

    public function __construct(
        SlugifyInterface $slugify
    ) {
        $this->slugify = $slugify;
    }

    public function setBaseTrackingData(array $trackingData): void
    {
        $this->trackingData = $trackingData;
    }

    public function getTrackingData(): ?array
    {
        return $this->trackingData;
    }

    public function addTrackingData(string $key, $value): void
    {
        if ($this->trackingData !== null) {
            $this->trackingData[$key] = $value;
        }
    }

    public function addTrackingDataArray(array $additionalData): void
    {
        if ($this->trackingData !== null) {
            $this->trackingData = array_merge($this->trackingData, $additionalData);
        }
    }

    public function resetTrackingData(): void
    {
        $this->trackingData = null;
    }

    public function getTransformedValue(string $value): string
    {
        return $this->slugify->slugify($value, [
            'separator' => '_',
            'lowercase' => true
        ]);
    }
}