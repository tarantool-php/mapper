<?php

namespace Tarantool\Mapper\Contracts;

interface Entity
{
    /**
     * @return int
     */
    public function getId();

    /**
     * @return Entity
     */
    public function setId($id);

    /**
     * @return array
     */
    public function toArray($recursive = false);

    /**
     * @return array
     */
    public function pullChanges();

    public function update($data);
}
