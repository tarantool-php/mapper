<?php


class SchemaTest extends PHPUnit_Framework_TestCase
{
    public function testPropertyRename()
    {
        $manager = Helper::createManager();
        $post = $manager->getMeta()->create('post', ['date', 'status']);
        $post->renameProperty('status', 'post_status');
        $this->assertSame($post->getProperties(), ['id', 'date', 'post_status']);

        $manager = Helper::createManager(false);
        $post = $manager->getMeta()->get('post');
        $this->assertSame($post->getProperties(), ['id', 'date', 'post_status']);
    }

    public function testPropertyFromMultiIndexShouldBeRemovedWhenRemoveWholeType()
    {
        $manager = Helper::createManager();
        $post = $manager->getMeta()->create('post', ['date', 'status']);
        $post->addIndex(['date', 'status']);
        $manager->getMeta()->remove('post');
    }

    public function testPropertyFromMultiIndexShouldNotBeRemoved()
    {
        $manager = Helper::createManager();
        $post = $manager->getMeta()->create('post', ['date', 'status']);
        $post->addIndex(['date', 'status']);
        $this->setExpectedException(Exception::class);
        $post->removeProperty('status');
    }

    public function testSpaceName()
    {
        $manager = Helper::createManager();
        $this->assertSame('_space', $manager->getSchema()->getSpaceName(280));
        $this->assertFalse($manager->getMeta()->has('_space'));
    }

    public function testTypeExistQuery()
    {
        $manager = Helper::createManager();
        $this->assertFalse($manager->getMeta()->has('person'));
        $manager->getMeta()->create('person');
        $this->assertTrue($manager->getMeta()->has('person'));

        $anotherManager = Helper::createManager(false);
        $this->assertTrue($anotherManager->getMeta()->has('person'));
    }

    public function testConventionOverride()
    {
        $meta = Helper::createManager()->getMeta();
        $this->assertNotNull($meta->getConvention());

        $convention = new Tarantool\Mapper\Schema\Convention();
        $meta->setConvention($convention);
        $this->assertSame($meta->getConvention(), $convention);

        $this->assertSame([1], $convention->encode('array', [1]));
    }
}
