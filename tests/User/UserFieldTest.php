<?php

namespace Themosis\Tests\User;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;
use Themosis\Field\Factory;
use Themosis\Forms\Fields\FieldsRepository;
use Themosis\Hook\ActionBuilder;
use Themosis\Support\Section;
use Themosis\Tests\Application;
use Themosis\Tests\ViewFactory;
use Themosis\User\UserField;

class UserFieldTest extends TestCase
{
    use Application;
    use ViewFactory;

    protected function getFieldFactory()
    {
        $app = $this->getApplication();

        return new Factory($app, $this->getViewFactory($app));
    }

    protected function getUserField()
    {
        $app = $this->getApplication();

        return new UserField(
            new FieldsRepository,
            new ActionBuilder($app),
            $this->getViewFactory($app),
            new \Illuminate\Validation\Factory(new Translator(new FileLoader(new Filesystem, ''), 'en_US')),
        );
    }

    public function test_user_with_wrong_options()
    {
        $user = $this->getUserField();

        $this->expectException(\InvalidArgumentException::class);

        $user->make(['unknown']);
    }

    public function test_user_add_fields_individually()
    {
        $fields = $this->getFieldFactory();
        $user = $this->getUserField();

        $user->make()
            ->add($text = $fields->text('something'))
            ->add($message = $fields->textarea('message'));

        $this->assertEquals($text, $user->repository()->getFieldByName('something'));
        $this->assertEquals($message, $user->repository()->getFieldByName('message'));
    }

    public function test_user_add_fields_with_sections()
    {
        $fields = $this->getFieldFactory();
        $user = $this->getUserField();

        $user->make()
            ->add(new Section('general', 'General', [
                $text = $fields->text('test'),
                $email = $fields->email('email'),
            ]));

        $this->assertEquals($text, $user->repository()->getFieldByName('test'));
        $this->assertEquals($email, $user->repository()->getFieldByName('email'));
    }
}
