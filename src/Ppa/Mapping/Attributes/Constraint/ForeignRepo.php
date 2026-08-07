<?php

declare(strict_types=1);

namespace Flytachi\Winter\Kernel\Ppa\Mapping\Attributes\Constraint;

use Attribute;
use Flytachi\Winter\Kernel\Ppa\Mapping\Constants\FKAction;
use Flytachi\Winter\Kernel\Ppa\Mapping\RepositoryMappingInterface;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class ForeignRepo implements AttributeDbConstraintForeign
{
    /**
     * @param class-string<RepositoryMappingInterface> $referencedRepoClass
     * @param FKAction $onUpdate
     * @param FKAction $onDelete
     * @param string|null $name
     */
    public function __construct(
        public string $referencedRepoClass,
        public FKAction $onUpdate = FKAction::RESTRICT,
        public FKAction $onDelete = FKAction::RESTRICT,
        public ?string $name = null,
    ) {
    }

    public function toObject(
        string $columnName,
        string $dialect = 'mysql'
    ): \Flytachi\Winter\Kernel\Ppa\Mapping\Structure\ForeignKey {
        $referencedRepoInstance = new $this->referencedRepoClass();

        if (!($referencedRepoInstance instanceof RepositoryMappingInterface)) {
            throw new \InvalidArgumentException(sprintf(
                'Class "%s" must implement DbMapRepoInterface.',
                $this->referencedRepoClass
            ));
        }

        return new \Flytachi\Winter\Kernel\Ppa\Mapping\Structure\ForeignKey(
            referencedTable: $referencedRepoInstance->originTable(),
            referencedColumn: $referencedRepoInstance->mapIdentifierColumnName(),
            onUpdate: $this->onUpdate,
            onDelete: $this->onDelete,
            name: $this->name,
            columnName: $columnName,
        );
    }
}
