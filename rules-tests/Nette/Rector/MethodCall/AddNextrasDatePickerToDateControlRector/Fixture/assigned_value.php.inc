<?php

namespace Rector\Tests\Nette\Rector\MethodCall\AddNextrasDatePickerToDateControlRector\Fixture;

use Nette\Application\UI\Form;

class AssignedValue
{
    public function run()
    {
        $form = new Form();
        $datePicker = $form->addDatePicker('key', 'Label');
    }
}

?>
-----
<?php

namespace Rector\Tests\Nette\Rector\MethodCall\AddNextrasDatePickerToDateControlRector\Fixture;

use Nette\Application\UI\Form;

class AssignedValue
{
    public function run()
    {
        $form = new Form();
        $datePicker = new \Nextras\FormComponents\Controls\DateControl('Label');
    }
}

?>