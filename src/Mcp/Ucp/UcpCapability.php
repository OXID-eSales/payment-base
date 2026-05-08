<?php

declare(strict_types=1);

namespace OxidEsales\PaymentBase\Mcp\Ucp;

readonly class UcpCapability
{
    /**
     * @param array<UcpCapability> $extensions
     */
    public function __construct(
        private string $name,
        private string $version,
        private ?string $spec = null,
        private array $extensions = []
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'name' => $this->name,
            'version' => $this->version,
        ];

        if ($this->spec !== null) {
            $result['spec'] = $this->spec;
        }

        if (!empty($this->extensions)) {
            $result['extensions'] = array_map(
                fn(UcpCapability $ext) => $ext->toArray(),
                $this->extensions
            );
        }

        return $result;
    }
}
