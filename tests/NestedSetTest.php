<?php

use Tarantool\Mapper\Plugin\NestedSet;
use Tarantool\Mapper\Plugin\Sequence;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Repository;

class NestedSetTest extends TestCase
{
    public function testMappingCasting()
    {
        $mapper = $this->createMapper();
        $this->clean($mapper);

        $mapper->addPlugin(Sequence::class);
        $nested = $mapper->addPlugin(NestedSet::class);

        $space = $mapper->getSchema()->createSpace('tree', [
            'id' => 'unsigned',
            'parent' => 'unsigned',
            'group' => 'unsigned',
            'depth' => 'unsigned',
            'left' => 'unsigned',
            'right' => 'unsigned',
        ]);

        $nested->addIndexes($space);

        $node1 = $mapper->create('tree', []);
        $this->assertSame($node1->left, 1);
        $this->assertSame($node1->right, 2);

        $node2 = $mapper->create('tree', []);
        $this->assertSame($node2->left, 3);
        $this->assertSame($node2->right, 4);

        $node3 = $mapper->create('tree', ['parent' => 2]);
        $this->assertSame($node3->depth, 1);
        $this->assertSame($node3->left, 4);
        $this->assertSame($node3->right, 5);
        $this->assertSame($node2->right, 6);

        $node4 = $mapper->create('tree', ['parent' => 3]);
        $this->assertSame($node4->depth, 2);
        $this->assertSame($node4->left, 5);
        $this->assertSame($node4->right, 6);
        $this->assertSame($node3->right, 7);
        $this->assertSame($node2->right, 8);

        $node5 = $mapper->create('tree', ['parent' => 1]);
        $this->assertSame($node5->left, 2);
        $this->assertSame($node5->right, 3);
        $this->assertSame($node2->right, 10);

        $mapper->remove($node1);

        $this->assertCount(3, $mapper->find('tree'));
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 6);

        $node7 = $mapper->create('tree', ['parent' => 2]);
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 8);

        $node8 = $mapper->create('tree', ['parent' => 2]);
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 10);

        $node9 = $mapper->create('tree', ['parent' => 7]);
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 12);

        $node10 = $mapper->create('tree', []);
        $this->assertSame($node10->left, 13);
        $this->assertSame($node10->right, 14);

        $node11 = $mapper->create('tree', ['parent' => 9]);
        $this->assertSame($node10->left, 13);
        $this->assertSame($node10->right, 16);

        // move nodes
        $node9->parent = 0;
        $mapper->save($node9);
        $this->assertSame($node7->left, 6);
        $this->assertSame($node7->right, 7);
        $this->assertSame($node8->left, 8);
        $this->assertSame($node8->right, 9);
        $this->assertSame($node9->left, 15);
        $this->assertSame($node9->right, 16);
        $this->assertSame($node9->depth, 0);
        $this->assertSame($node10->left, 11);
        $this->assertSame($node10->right, 14);

        $node10->parent = 6;
        $mapper->save($node10);
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 14);
        $this->assertSame($node7->left, 6);
        $this->assertSame($node7->right, 11);
        $this->assertSame($node10->left, 7);
        $this->assertSame($node10->right, 10);
        $this->assertSame($node10->depth, 2);
        $this->assertSame($node11->left, 8);
        $this->assertSame($node11->right, 9);
        $this->assertSame($node11->depth, 3);

        $node11->parent = 8;
        $mapper->save($node11);
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 12);
        $this->assertSame($node7->left, 6);
        $this->assertSame($node7->right, 9);
        $this->assertSame($node10->left, 7);
        $this->assertSame($node10->right, 8);
        $this->assertSame($node10->depth, 2);
        $this->assertSame($node9->left, 13);
        $this->assertSame($node9->right, 16);
        $this->assertSame($node11->left, 14);
        $this->assertSame($node11->right, 15);
        $this->assertSame($node11->depth, 1);

        // new group test

        $node1 = $mapper->create('tree', ['group' => 1]);
        $this->assertSame($node1->left, 1);
        $this->assertSame($node1->right, 2);

        $node2 = $mapper->create('tree', ['group' => 1]);
        $this->assertSame($node2->left, 3);
        $this->assertSame($node2->right, 4);

        $node3 = $mapper->create('tree', ['parent' => $node2->id, 'group' => 1]);
        $this->assertSame($node3->depth, 1);
        $this->assertSame($node3->left, 4);
        $this->assertSame($node3->right, 5);
        $this->assertSame($node2->right, 6);

        $node4 = $mapper->create('tree', ['parent' => $node3->id, 'group' => 1]);
        $this->assertSame($node4->depth, 2);
        $this->assertSame($node4->left, 5);
        $this->assertSame($node4->right, 6);
        $this->assertSame($node3->right, 7);
        $this->assertSame($node2->right, 8);

        $node5 = $mapper->create('tree', ['parent' => $node1->id, 'group' => 1]);
        $this->assertSame($node5->left, 2);
        $this->assertSame($node5->right, 3);
        $this->assertSame($node2->right, 10);
        $mapper->remove($node1);

        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 6);

        $node7 = $mapper->create('tree', ['parent' => $node2->id, 'group' => 1]);
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 8);

        $node8 = $mapper->create('tree', ['parent' => $node2->id, 'group' => 1]);
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 10);

        $node9 = $mapper->create('tree', ['parent' => $node7->id, 'group' => 1]);
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 12);

        // move nodes in group 1

        $node9->parent = 0;
        $mapper->save($node9);
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 10);
        $this->assertSame($node7->left, 6);
        $this->assertSame($node7->right, 7);
        $this->assertSame($node8->left, 8);
        $this->assertSame($node8->right, 9);
        $this->assertSame($node9->left, 11);
        $this->assertSame($node9->right, 12);
        $this->assertSame($node9->depth, 0);

        $node3->parent = $node7->id;
        $mapper->save($node3);
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 10);
        $this->assertSame($node3->left, 3);
        $this->assertSame($node3->right, 6);
        $this->assertSame($node3->depth, 2);
        $this->assertSame($node7->left, 2);
        $this->assertSame($node7->right, 7);
        $this->assertSame($node8->left, 8);
        $this->assertSame($node8->right, 9);
        $this->assertSame($node9->left, 11);
        $this->assertSame($node9->right, 12);

        $node9->parent = $node4->id;
        $mapper->save($node9);
        $this->assertSame($node2->left, 1);
        $this->assertSame($node2->right, 12);
        $this->assertSame($node3->left, 3);
        $this->assertSame($node3->right, 8);
        $this->assertSame($node7->left, 2);
        $this->assertSame($node7->right, 9);
        $this->assertSame($node8->left, 10);
        $this->assertSame($node8->right, 11);
        $this->assertSame($node9->left, 5);
        $this->assertSame($node9->right, 6);
        $this->assertSame($node9->depth, 4);
    }
}
