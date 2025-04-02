<?php declare(strict_types=1);

namespace SwClp\MatomoServerTagManager;

use Exception;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\HttpKernel\KernelInterface;

class MatomoServerTagManager extends Plugin
{
    /**
     * @throws Exception
     */
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        $this->createCustomFields($installContext);

        if ($installContext->getContext()->getScope() === Context::SYSTEM_SCOPE) {
            $this->installAssets();
        }

    }

    /**
     * @throws Exception
     */
    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        $this->createCustomFields($updateContext);

        if ($updateContext->getContext()->getScope() === Context::SYSTEM_SCOPE) {
            $this->installAssets();
        }
    }

    /**
     * @throws Exception
     */
    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);

        if ($activateContext->getContext()->getScope() === Context::SYSTEM_SCOPE) {
            $this->installAssets();
        }
    }

    private function createCustomFields(InstallContext|UpdateContext $context): void
    {
        $fieldSetName = 'custom_matomo_tracking';
        $orderFieldName = 'custom_matomo_tracking_order_success';
        $clickIdFieldName = 'custom_google_click_id';
        $shopwareContext = $context->getContext();

        $customFieldSetRepository = $this->container->get('custom_field_set.repository');
        $customFieldRepository = $this->container->get('custom_field.repository');

        $fieldSetConfig = [
            'label' => [
                'de-DE' => 'Matomo Tracking',
                'en-GB' => 'Matomo Tracking'
            ]
        ];

        $orderFieldConfig = [
            'name' => $orderFieldName,
            'type' => CustomFieldTypes::CHECKBOX,
            'config' => [
                'label' => [
                    'de-DE' => 'Matomo Tracking Order',
                    'en-GB' => 'Matomo Tracking Order'
                ],
                'customFieldPosition' => 1,
                'componentName' => 'sw-field',
                'type' => 'checkbox',
                'defaultValue' => false
            ],
        ];

        $clickIdFieldConfig = [
            'name' => $clickIdFieldName,
            'type' => CustomFieldTypes::TEXT,
            'config' => [
                'label' => [
                    'de-DE' => 'Google Click-ID',
                    'en-GB' => 'Google Click-ID'
                ],
                'customFieldPosition' => 2,
                'componentName' => 'sw-field',
                'type' => 'text',
                'defaultValue' => false
            ],
        ];

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $fieldSetName));
        $existingCustomFieldSet = $customFieldSetRepository->search($criteria, $shopwareContext)->first();

        if ($existingCustomFieldSet) {
            $customFieldSetRepository->update([
                [
                    'id' => $existingCustomFieldSet->getId(),
                    'config' => $fieldSetConfig
                ]
            ], $shopwareContext);

            $orderFieldCriteria = new Criteria();
            $orderFieldCriteria->addFilter(new EqualsFilter('name', $orderFieldName));
            $orderFieldCriteria->addFilter(new EqualsFilter('customFieldSetId', $existingCustomFieldSet->getId()));
            $existingOrderField = $customFieldRepository->search($orderFieldCriteria, $shopwareContext)->first();

            if (!$existingOrderField) {
                $orderFieldConfig['customFieldSetId'] = $existingCustomFieldSet->getId();
                $customFieldRepository->create([$orderFieldConfig], $shopwareContext);
            }

            $clickIdFieldCriteria = new Criteria();
            $clickIdFieldCriteria->addFilter(new EqualsFilter('name', $clickIdFieldName));
            $clickIdFieldCriteria->addFilter(new EqualsFilter('customFieldSetId', $existingCustomFieldSet->getId()));
            $existingClickIdField = $customFieldRepository->search($clickIdFieldCriteria, $shopwareContext)->first();

            if (!$existingClickIdField) {
                $clickIdFieldConfig['customFieldSetId'] = $existingCustomFieldSet->getId();
                $customFieldRepository->create([$clickIdFieldConfig], $shopwareContext);
            }

            return;
        }

        $customFieldSetRepository->create([
            [
                'id' => Uuid::randomHex(),
                'name' => $fieldSetName,
                'config' => $fieldSetConfig,
                'customFields' => [$orderFieldConfig, $clickIdFieldConfig],
                'relations' => [[
                    'entityName' => 'order'
                ]]
            ]
        ], $shopwareContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        $this->removeCustomFields($uninstallContext);
        parent::uninstall($uninstallContext);
    }

    private function removeCustomFields(UninstallContext $context): void
    {
        if ($context->keepUserData()) {
            return;
        }

        $fieldSetName = 'custom_matomo_tracking';
        $shopwareContext = $context->getContext();

        $customFieldSetRepository = $this->container->get('custom_field_set.repository');
        $customFieldRepository = $this->container->get('custom_field.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $fieldSetName));
        $customFieldSet = $customFieldSetRepository->search($criteria, $shopwareContext)->first();

        if (!$customFieldSet) {
            return;
        }

        $customFieldSetId = $customFieldSet->getId();
        $customFieldCriteria = new Criteria();
        $customFieldCriteria->addFilter(new EqualsFilter('customFieldSetId', $customFieldSetId));
        $customFieldIds = $customFieldRepository->searchIds($customFieldCriteria, $shopwareContext)->getIds();

        if (!empty($customFieldIds)) {
            $customFieldRepository->delete(
                array_map(fn($id) => ['id' => $id], $customFieldIds),
                $shopwareContext
            );
        }

        $customFieldSetRepository->delete([['id' => $customFieldSetId]], $shopwareContext);
    }

    /**
     * @throws Exception
     */
    private function installAssets(): void
    {
        /** @var KernelInterface $kernel */
        $kernel = $this->container->get('kernel');

        $application = new Application($kernel);
        $application->setAutoExit(false);

        $application->run(new ArrayInput([
            'command' => 'assets:install',
            '--no-interaction' => true,
            '--force' => true
        ]), new NullOutput());

        $application->run(new ArrayInput([
            'command' => 'theme:compile',
            '--no-interaction' => true
        ]), new NullOutput());
    }
}
