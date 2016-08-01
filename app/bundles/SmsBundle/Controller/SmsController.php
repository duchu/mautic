<?php
/**
 * @copyright   2016 Mautic Contributors. All rights reserved.
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
namespace Mautic\SmsBundle\Controller;

use Mautic\CoreBundle\Controller\FormController;
use Mautic\CoreBundle\Helper\InputHelper;
use Mautic\SmsBundle\Entity\StatRepository;
use Mautic\SmsBundle\Entity\Sms;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class SmsController extends FormController
{
    /**
     * @param int $page
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function indexAction($page = 1)
    {
        /** @var \Mautic\SmsBundle\Model\SmsModel $model */
        $model = $this->getModel('sms');

        //set some permissions
        $permissions = $this->get('mautic.security')->isGranted(
            [
                'sms:smses:viewown',
                'sms:smses:viewother',
                'sms:smses:create',
                'sms:smses:editown',
                'sms:smses:editother',
                'sms:smses:deleteown',
                'sms:smses:deleteother',
                'sms:smses:publishown',
                'sms:smses:publishother',
            ],
            'RETURN_ARRAY'
        );

        if (!$permissions['sms:smses:viewown'] && !$permissions['sms:smses:viewother']) {
            return $this->accessDenied();
        }

        if ($this->request->getMethod() == 'POST') {
            $this->setListFilters();
        }

        $session = $this->get('session');

        $listFilters = [
            'filters' => [
                'multiple' => true,
            ],
        ];

        // Reset available groups
        $listFilters['filters']['groups'] = [];

        //set limits
        $limit = $session->get('mautic.sms.limit', $this->factory->getParameter('default_pagelimit'));
        $start = ($page === 1) ? 0 : (($page - 1) * $limit);
        if ($start < 0) {
            $start = 0;
        }

        $search = $this->request->get('search', $session->get('mautic.sms.filter', ''));
        $session->set('mautic.email.filter', $search);

        $filter = ['string' => $search];

        if (!$permissions['sms:smses:viewother']) {
            $filter['force'][] =
                ['column' => 'e.createdBy', 'expr' => 'eq', 'value' => $this->factory->getUser()->getId()];
        }

        //retrieve a list of categories
        $listFilters['filters']['groups']['mautic.core.filter.categories'] = [
            'options' => $this->getModel('category')->getLookupResults('email', '', 0),
            'prefix' => 'category',
        ];

        //retrieve a list of Lead Lists
        $listFilters['filters']['groups']['mautic.core.filter.lists'] = [
            'options' => $this->getModel('lead.list')->getUserLists(),
            'prefix' => 'list',
        ];

        //retrieve a list of themes
        $listFilters['filters']['groups']['mautic.core.filter.themes'] = [
            'options' => $this->factory->getInstalledThemes('email'),
            'prefix' => 'theme',
        ];

        $currentFilters = $session->get('mautic.sms.list_filters', []);
        $updatedFilters = $this->request->get('filters', false);

        if ($updatedFilters) {
            // Filters have been updated

            // Parse the selected values
            $newFilters = [];
            $updatedFilters = json_decode($updatedFilters, true);

            if ($updatedFilters) {
                foreach ($updatedFilters as $updatedFilter) {
                    list($clmn, $fltr) = explode(':', $updatedFilter);

                    $newFilters[$clmn][] = $fltr;
                }

                $currentFilters = $newFilters;
            } else {
                $currentFilters = [];
            }
        }
        $session->set('mautic.sms.list_filters', $currentFilters);

        if (!empty($currentFilters)) {
            $listIds = $catIds = [];
            foreach ($currentFilters as $type => $typeFilters) {
                switch ($type) {
                    case 'list':
                        $key = 'lists';
                        break;
                    case 'category':
                        $key = 'categories';
                        break;
                }

                $listFilters['filters']['groups']['mautic.core.filter.'.$key]['values'] = $typeFilters;

                foreach ($typeFilters as $fltr) {
                    switch ($type) {
                        case 'list':
                            $listIds[] = (int) $fltr;
                            break;
                        case 'category':
                            $catIds[] = (int) $fltr;
                            break;
                    }
                }
            }

            if (!empty($listIds)) {
                $filter['force'][] = ['column' => 'l.id', 'expr' => 'in', 'value' => $listIds];
            }

            if (!empty($catIds)) {
                $filter['force'][] = ['column' => 'c.id', 'expr' => 'in', 'value' => $catIds];
            }
        }

        $orderBy = $session->get('mautic.sms.orderby', 'e.name');
        $orderByDir = $session->get('mautic.sms.orderbydir', 'DESC');

        $smss = $model->getEntities([
            'start' => $start,
            'limit' => $limit,
            'filter' => $filter,
            'orderBy' => $orderBy,
            'orderByDir' => $orderByDir,
        ]);

        $count = count($smss);
        if ($count && $count < ($start + 1)) {
            //the number of entities are now less then the current page so redirect to the last page
            if ($count === 1) {
                $lastPage = 1;
            } else {
                $lastPage = (floor($count / $limit)) ?: 1;
            }

            $session->set('mautic.sms.page', $lastPage);
            $returnUrl = $this->generateUrl('mautic_sms_index', ['page' => $lastPage]);

            return $this->postActionRedirect([
                'returnUrl' => $returnUrl,
                'viewParameters' => ['page' => $lastPage],
                'contentTemplate' => 'MauticSmsBundle:Sms:index',
                'passthroughVars' => [
                    'activeLink' => '#mautic_sms_index',
                    'mauticContent' => 'sms',
                ],
            ]);
        }
        $session->set('mautic.sms.page', $page);

        return $this->delegateView([
            'viewParameters' => [
                'searchValue' => $search,
                'filters' => $listFilters,
                'items' => $smss,
                'totalItems' => $count,
                'page' => $page,
                'limit' => $limit,
                'tmpl' => $this->request->get('tmpl', 'index'),
                'permissions' => $permissions,
                'model' => $model,
                'security' => $this->get('mautic.security'),
                'configured' => $this->factory->getParameter('sms_enabled'),
            ],
            'contentTemplate' => 'MauticSmsBundle:Sms:list.html.php',
            'passthroughVars' => [
                'activeLink' => '#mautic_sms_index',
                'mauticContent' => 'sms',
                'route' => $this->generateUrl('mautic_sms_index', ['page' => $page]),
            ],
        ]);
    }

    /**
     * Loads a specific form into the detailed panel.
     *
     * @param $objectId
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function viewAction($objectId)
    {
        /** @var \Mautic\SmsBundle\Model\SmsModel $model */
        $model = $this->getModel('sms');
        $security = $this->get('mautic.security');

        /** @var \Mautic\SmsBundle\Entity\Sms $sms */
        $sms = $model->getEntity($objectId);
        //set the page we came from
        $page = $this->get('session')->get('mautic.sms.page', 1);

        if ($sms === null) {
            //set the return URL
            $returnUrl = $this->generateUrl('mautic_sms_index', ['page' => $page]);

            return $this->postActionRedirect([
                'returnUrl' => $returnUrl,
                'viewParameters' => ['page' => $page],
                'contentTemplate' => 'MauticSmsBundle:Sms:index',
                'passthroughVars' => [
                    'activeLink' => '#mautic_sms_index',
                    'mauticContent' => 'sms',
                ],
                'flashes' => [
                    [
                        'type' => 'error',
                        'msg' => 'mautic.sms.error.notfound',
                        'msgVars' => ['%id%' => $objectId],
                    ],
                ],
            ]);
        } elseif (!$this->get('mautic.security')->hasEntityAccess(
            'sms:smses:viewown',
            'sms:smses:viewother',
            $sms->getCreatedBy()
        )
        ) {
            return $this->accessDenied();
        }

        // Audit Log
        $logs = $this->getModel('core.auditLog')->getLogForObject('sms', $sms->getId(), $sms->getDateAdded());

        // Init the date range filter form
        $dateRangeValues = $this->request->get('daterange', []);
        $action = $this->generateUrl('mautic_sms_action', ['objectAction' => 'view', 'objectId' => $objectId]);
        $dateRangeForm = $this->get('form.factory')->create('daterange', $dateRangeValues, ['action' => $action]);
        $entityViews = $model->getHitsLineChartData(
            null,
            new \DateTime($dateRangeForm->get('date_from')->getData()),
            new \DateTime($dateRangeForm->get('date_to')->getData()),
            null,
            ['sms_id' => $sms->getId()]
        );

        // Get click through stats
        $trackableLinks = $model->getSmsClickStats($sms->getId());

        return $this->delegateView([
            'returnUrl' => $this->generateUrl('mautic_sms_action', ['objectAction' => 'view', 'objectId' => $sms->getId()]),
            'viewParameters' => [
                'sms' => $sms,
                'trackables' => $trackableLinks,
                'logs' => $logs,
                'permissions' => $security->isGranted([
                    'sms:smses:viewown',
                    'sms:smses:viewother',
                    'sms:smses:create',
                    'sms:smses:editown',
                    'sms:smses:editother',
                    'sms:smses:deleteown',
                    'sms:smses:deleteother',
                    'sms:smses:publishown',
                    'sms:smses:publishother',
                ], 'RETURN_ARRAY'),
                'security' => $security,
                'entityViews' => $entityViews,
                'contacts' => $this->forward(
                    'MauticSmsBundle:Sms:contacts',
                    [
                        'objectId' => $sms->getId(),
                        'page' => $this->get('session')->get('mautic.sms.contact.page', 1),
                        'ignoreAjax' => true,
                    ]
                )->getContent(),
                'dateRangeForm' => $dateRangeForm->createView(),
            ],
            'contentTemplate' => 'MauticSmsBundle:Sms:details.html.php',
            'passthroughVars' => [
                'activeLink' => '#mautic_sms_index',
                'mauticContent' => 'sms',
            ],
        ]);
    }

    /**
     * Generates new form and processes post data.
     *
     * @param Sms $entity
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function newAction($entity = null)
    {
        /** @var \Mautic\SmsBundle\Model\SmsModel $model */
        $model = $this->getModel('sms');

        if (!$entity instanceof Sms) {
            /** @var \Mautic\SmsBundle\Entity\Sms $entity */
            $entity = $model->getEntity();
        }

        $method = $this->request->getMethod();
        $session = $this->get('session');

        if (!$this->get('mautic.security')->isGranted('sms:smses:create')) {
            return $this->accessDenied();
        }

        //set the page we came from
        $page = $session->get('mautic.sms.page', 1);
        $action = $this->generateUrl('mautic_sms_action', ['objectAction' => 'new']);

        $updateSelect = ($method == 'POST')
            ? $this->request->request->get('sms[updateSelect]', false, true)
            : $this->request->get('updateSelect', false);

        if ($updateSelect) {
            $entity->setSmsType('template');
        }

        //create the form
        $form = $model->createForm($entity, $this->get('form.factory'), $action, ['update_select' => $updateSelect]);

        ///Check for a submitted form and process it
        if ($method == 'POST') {
            $valid = false;
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    //form is valid so process the data
                    $model->saveEntity($entity);

                    $this->addFlash(
                        'mautic.core.notice.created',
                        [
                            '%name%' => $entity->getName(),
                            '%menu_link%' => 'mautic_sms_index',
                            '%url%' => $this->generateUrl(
                                'mautic_sms_action',
                                [
                                    'objectAction' => 'edit',
                                    'objectId' => $entity->getId(),
                                ]
                            ),
                        ]
                    );

                    if ($form->get('buttons')->get('save')->isClicked()) {
                        $viewParameters = [
                            'objectAction' => 'view',
                            'objectId' => $entity->getId(),
                        ];
                        $returnUrl = $this->generateUrl('mautic_sms_action', $viewParameters);
                        $template = 'MauticSmsBundle:Sms:view';
                    } else {
                        //return edit view so that all the session stuff is loaded
                        return $this->editAction($entity->getId(), true);
                    }
                }
            } else {
                $viewParameters = ['page' => $page];
                $returnUrl = $this->generateUrl('mautic_sms_index', $viewParameters);
                $template = 'MauticSmsBundle:Sms:index';
                //clear any modified content
                $session->remove('mautic.sms.'.$entity->getSessionId().'.content');
            }

            $passthrough = [
                'activeLink' => 'mautic_sms_index',
                'mauticContent' => 'sms',
            ];

            // Check to see if this is a popup
            if (isset($form['updateSelect'])) {
                $template = false;
                $passthrough = array_merge(
                    $passthrough,
                    [
                        'updateSelect' => $form['updateSelect']->getData(),
                        'smsId' => $entity->getId(),
                        'smsName' => $entity->getName(),
                        'smsLang' => $entity->getLanguage(),
                    ]
                );
            }

            if ($cancelled || ($valid && $form->get('buttons')->get('save')->isClicked())) {
                return $this->postActionRedirect(
                    [
                        'returnUrl' => $returnUrl,
                        'viewParameters' => $viewParameters,
                        'contentTemplate' => $template,
                        'passthroughVars' => $passthrough,
                    ]
                );
            }
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'form' => $this->setFormTheme($form, 'MauticSmsBundle:Sms:form.html.php', 'MauticSmsBundle:FormTheme\Sms'),
                    'sms' => $entity,
                ],
                'contentTemplate' => 'MauticSmsBundle:Sms:form.html.php',
                'passthroughVars' => [
                    'activeLink' => '#mautic_sms_index',
                    'mauticContent' => 'sms',
                    'updateSelect' => InputHelper::clean($this->request->query->get('updateSelect')),
                    'route' => $this->generateUrl(
                        'mautic_sms_action',
                        [
                            'objectAction' => 'new',
                        ]
                    ),
                ],
            ]
        );
    }

    /**
     * @param      $objectId
     * @param bool $ignorePost
     * @param bool $forceTypeSelection
     *
     * @return array|\Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function editAction($objectId, $ignorePost = false, $forceTypeSelection = false)
    {
        /** @var \Mautic\SmsBundle\Model\SmsModel $model */
        $model = $this->getModel('sms');
        $method = $this->request->getMethod();
        $entity = $model->getEntity($objectId);
        $session = $this->get('session');
        $page = $session->get('mautic.sms.page', 1);

        //set the return URL
        $returnUrl = $this->generateUrl('mautic_sms_index', ['page' => $page]);

        $postActionVars = [
            'returnUrl' => $returnUrl,
            'viewParameters' => ['page' => $page],
            'contentTemplate' => 'MauticSmsBundle:Sms:index',
            'passthroughVars' => [
                'activeLink' => 'mautic_sms_index',
                'mauticContent' => 'sms',
            ],
        ];

        //not found
        if ($entity === null) {
            return $this->postActionRedirect(
                array_merge(
                    $postActionVars,
                    [
                        'flashes' => [
                            [
                                'type' => 'error',
                                'msg' => 'mautic.sms.error.notfound',
                                'msgVars' => ['%id%' => $objectId],
                            ],
                        ],
                    ]
                )
            );
        } elseif (!$this->get('mautic.security')->hasEntityAccess(
            'sms:smses:viewown',
            'sms:smses:viewother',
            $entity->getCreatedBy()
        )
        ) {
            return $this->accessDenied();
        } elseif ($model->isLocked($entity)) {
            //deny access if the entity is locked
            return $this->isLocked($postActionVars, $entity, 'email');
        }

        //Create the form
        $action = $this->generateUrl('mautic_sms_action', ['objectAction' => 'edit', 'objectId' => $objectId]);

        $updateSelect = ($method == 'POST')
            ? $this->request->request->get('sms[updateSelect]', false, true)
            : $this->request->get('updateSelect', false);

        $form = $model->createForm($entity, $this->get('form.factory'), $action, ['update_select' => $updateSelect]);

        ///Check for a submitted form and process it
        if (!$ignorePost && $method == 'POST') {
            $valid = false;
            if (!$cancelled = $this->isFormCancelled($form)) {
                if ($valid = $this->isFormValid($form)) {
                    //form is valid so process the data
                    $model->saveEntity($entity, $form->get('buttons')->get('save')->isClicked());

                    $this->addFlash(
                        'mautic.core.notice.updated',
                        [
                            '%name%' => $entity->getName(),
                            '%menu_link%' => 'mautic_sms_index',
                            '%url%' => $this->generateUrl(
                                'mautic_sms_action',
                                [
                                    'objectAction' => 'edit',
                                    'objectId' => $entity->getId(),
                                ]
                            ),
                        ],
                        'warning'
                    );
                }
            } else {
                //clear any modified content
                $session->remove('mautic.sms.'.$objectId.'.content');
                //unlock the entity
                $model->unlockEntity($entity);
            }

            $passthrough = [
                'activeLink' => 'mautic_sms_index',
                'mauticContent' => 'sms',
            ];

            $template = 'MauticSmsBundle:Sms:view';

            // Check to see if this is a popup
            if (isset($form['updateSelect'])) {
                $template = false;
                $passthrough = array_merge(
                    $passthrough,
                    [
                        'updateSelect' => $form['updateSelect']->getData(),
                        'smsId' => $entity->getId(),
                        'smsName' => $entity->getName(),
                        'smsLang' => $entity->getLanguage(),
                    ]
                );
            }

            if ($cancelled || ($valid && $form->get('buttons')->get('save')->isClicked())) {
                $viewParameters = [
                    'objectAction' => 'view',
                    'objectId' => $entity->getId(),
                ];

                return $this->postActionRedirect(
                    array_merge(
                        $postActionVars,
                        [
                            'returnUrl' => $this->generateUrl('mautic_sms_action', $viewParameters),
                            'viewParameters' => $viewParameters,
                            'contentTemplate' => $template,
                            'passthroughVars' => $passthrough,
                        ]
                    )
                );
            }
        } else {
            //lock the entity
            $model->lockEntity($entity);
        }

        return $this->delegateView(
            [
                'viewParameters' => [
                    'form' => $this->setFormTheme($form, 'MauticSmsBundle:Sms:form.html.php', 'MauticSmsBundle:FormTheme\Sms'),
                    'sms' => $entity,
                    'forceTypeSelection' => $forceTypeSelection,
                ],
                'contentTemplate' => 'MauticSmsBundle:Sms:form.html.php',
                'passthroughVars' => [
                    'activeLink' => '#mautic_sms_index',
                    'mauticContent' => 'sms',
                    'updateSelect' => InputHelper::clean($this->request->query->get('updateSelect')),
                    'route' => $this->generateUrl(
                        'mautic_sms_action',
                        [
                            'objectAction' => 'edit',
                            'objectId' => $entity->getId(),
                        ]
                    ),
                ],
            ]
        );
    }

    /**
     * Clone an entity.
     *
     * @param $objectId
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function cloneAction($objectId)
    {
        $model = $this->getModel('sms');
        $entity = $model->getEntity($objectId);

        if ($entity != null) {
            if (!$this->get('mautic.security')->isGranted('sms:smses:create')
                || !$this->get('mautic.security')->hasEntityAccess(
                    'sms:smses:viewown',
                    'sms:smses:viewother',
                    $entity->getCreatedBy()
                )
            ) {
                return $this->accessDenied();
            }

            $entity = clone $entity;
            $session = $this->get('session');
            $contentName = 'mautic.sms.'.$entity->getSessionId().'.content';

            $session->set($contentName, $entity->getContent());
        }

        return $this->newAction($entity);
    }

    /**
     * Deletes the entity.
     *
     * @param   $objectId
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteAction($objectId)
    {
        $page = $this->get('session')->get('mautic.sms.page', 1);
        $returnUrl = $this->generateUrl('mautic_sms_index', ['page' => $page]);
        $flashes = [];

        $postActionVars = [
            'returnUrl' => $returnUrl,
            'viewParameters' => ['page' => $page],
            'contentTemplate' => 'MauticSmsBundle:Sms:index',
            'passthroughVars' => [
                'activeLink' => 'mautic_sms_index',
                'mauticContent' => 'sms',
            ],
        ];

        if ($this->request->getMethod() == 'POST') {
            $model = $this->getModel('sms');
            $entity = $model->getEntity($objectId);

            if ($entity === null) {
                $flashes[] = [
                    'type' => 'error',
                    'msg' => 'mautic.sms.error.notfound',
                    'msgVars' => ['%id%' => $objectId],
                ];
            } elseif (!$this->get('mautic.security')->hasEntityAccess(
                'sms:smses:deleteown',
                'sms:smses:deleteother',
                $entity->getCreatedBy()
            )
            ) {
                return $this->accessDenied();
            } elseif ($model->isLocked($entity)) {
                return $this->isLocked($postActionVars, $entity, 'sms');
            }

            $model->deleteEntity($entity);

            $flashes[] = [
                'type' => 'notice',
                'msg' => 'mautic.core.notice.deleted',
                'msgVars' => [
                    '%name%' => $entity->getTitle(),
                    '%id%' => $objectId,
                ],
            ];
        } //else don't do anything

        return $this->postActionRedirect(
            array_merge(
                $postActionVars,
                ['flashes' => $flashes]
            )
        );
    }

    /**
     * Deletes a group of entities.
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function batchDeleteAction()
    {
        $page = $this->get('session')->get('mautic.sms.page', 1);
        $returnUrl = $this->generateUrl('mautic_sms_index', ['page' => $page]);
        $flashes = [];

        $postActionVars = [
            'returnUrl' => $returnUrl,
            'viewParameters' => ['page' => $page],
            'contentTemplate' => 'MauticSmsBundle:Sms:index',
            'passthroughVars' => [
                'activeLink' => '#mautic_sms_index',
                'mauticContent' => 'sms',
            ],
        ];

        if ($this->request->getMethod() == 'POST') {
            $model = $this->getModel('sms');
            $ids = json_decode($this->request->query->get('ids', '{}'));

            $deleteIds = [];

            // Loop over the IDs to perform access checks pre-delete
            foreach ($ids as $objectId) {
                $entity = $model->getEntity($objectId);

                if ($entity === null) {
                    $flashes[] = [
                        'type' => 'error',
                        'msg' => 'mautic.sms.error.notfound',
                        'msgVars' => ['%id%' => $objectId],
                    ];
                } elseif (!$this->get('mautic.security')->hasEntityAccess(
                    'sms:smses:viewown',
                    'sms:smses:viewother',
                    $entity->getCreatedBy()
                )
                ) {
                    $flashes[] = $this->accessDenied(true);
                } elseif ($model->isLocked($entity)) {
                    $flashes[] = $this->isLocked($postActionVars, $entity, 'sms', true);
                } else {
                    $deleteIds[] = $objectId;
                }
            }

            // Delete everything we are able to
            if (!empty($deleteIds)) {
                $entities = $model->deleteEntities($deleteIds);

                $flashes[] = [
                    'type' => 'notice',
                    'msg' => 'mautic.sms.notice.batch_deleted',
                    'msgVars' => [
                        '%count%' => count($entities),
                    ],
                ];
            }
        } //else don't do anything

        return $this->postActionRedirect(
            array_merge(
                $postActionVars,
                ['flashes' => $flashes]
            )
        );
    }

    /**
     * @param $objectId
     *
     * @return JsonResponse|Response
     */
    public function previewAction($objectId)
    {
        /** @var \Mautic\SmsBundle\Model\SmsModel $model */
        $model = $this->getModel('sms');
        $sms = $model->getEntity($objectId);
        $security = $this->get('mautic.security');

        if ($sms !== null && $security->hasEntityAccess('sms:smses:viewown', 'sms:smses:viewother')) {
            return $this->delegateView([
                'viewParameters' => [
                    'sms' => $sms,
                ],
                'contentTemplate' => 'MauticSmsBundle:Sms:preview.html.php',
            ]);
        }

        return new Response('', Response::HTTP_NOT_FOUND);
    }

    /**
     * @param     $objectId
     * @param int $page
     *
     * @return JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse|Response
     */
    public function contactsAction($objectId, $page = 1)
    {
        if (!$this->get('mautic.security')->isGranted('sms:smses:view')) {
            return $this->accessDenied();
        }

        if ($this->request->getMethod() == 'POST') {
            $this->setListFilters();
        }

        //set limits
        $limit = $this->get('session')->get('mautic.sms.contact.limit', $this->factory->getParameter('default_pagelimit'));
        $start = ($page === 1) ? 0 : (($page - 1) * $limit);
        if ($start < 0) {
            $start = 0;
        }

        $search = $this->request->get('search', $this->get('session')->get('mautic.sms.contact.filter', ''));
        $this->get('session')->set('mautic.sms.contact.filter', $search);

        $filter = ['string' => $search, 'force' => []];
        $orderBy = $this->get('session')->get('mautic.sms.contact.orderby', 'l.id');
        $orderByDir = $this->get('session')->get('mautic.sms.contact.orderbydir', 'DESC');

        // We need the EmailRepository to check if a lead is flagged as do not contact
        /** @var \Mautic\EmailBundle\Entity\EmailRepository $emailRepo */
        $emailRepo = $this->getModel('email')->getRepository();

        /** @var StatRepository $smsStatRepo */
        $smsStatRepo = $this->getModel('sms')->getStatRepository();
        $contacts = $smsStatRepo->getLeadsWithFields([
            'sms_id' => $objectId,
            'withTotalCount' => true,
            'start' => $start,
            'limit' => $limit,
            'filter' => $filter,
            'orderBy' => $orderBy,
            'orderByDir' => $orderByDir,
        ]);

        $count = $contacts['count'];
        if ($count && $count < ($start + 1)) {
            //the number of entities are now less then the current page so redirect to the last page
            if ($count === 1) {
                $lastPage = 1;
            } else {
                $lastPage = (ceil($count / $limit)) ?: 1;
            }
            $this->get('session')->set('mautic.sms.contact.page', $lastPage);
            $returnUrl = $this->generateUrl('mautic_sms_contacts', ['objectId' => $objectId, 'page' => $lastPage]);

            return $this->postActionRedirect([
                'returnUrl' => $returnUrl,
                'viewParameters' => ['page' => $lastPage, 'objectId' => $objectId],
                'contentTemplate' => 'MauticSmsBundle:Sms:contacts.html.php',
                'passthroughVars' => [
                    'mauticContent' => 'smsContacts',
                ],
            ]);
        }

        $triggerModel = $this->getModel('point.trigger');
        foreach ($contacts['results'] as &$l) {
            $l['color'] = $triggerModel->getColorForLeadPoints($l['points']);
        }

        return $this->delegateView([
            'viewParameters' => [
                'page' => $page,
                'items' => $contacts['results'],
                'totalItems' => $contacts['count'],
                'tmpl' => 'smscontacts',
                'indexMode' => 'grid',
                'link' => 'mautic_sms_contacts',
                'sessionVar' => 'sms.contact',
                'limit' => $limit,
                'objectId' => $objectId,
                'noContactList' => $emailRepo->getDoNotEmailList(),
            ],
            'contentTemplate' => 'MauticSmsBundle:Sms:contacts.html.php',
            'passthroughVars' => [
                'mauticContent' => 'smsContacts',
                'route' => false,
            ],
        ]);
    }
}
