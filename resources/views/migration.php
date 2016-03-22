<?php echo '<?php' , PHP_EOL; ?>

use Tarantool\Mapper\Contracts\Manager;
use Tarantool\Mapper\Contracts\Migration;

class <?php echo $class; ?> implements Migration
{
    public function migrate(Manager $manager)
    {
        // put your code here
    }
}