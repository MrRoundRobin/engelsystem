<?php

use Engelsystem\Models\AngelType;
use Engelsystem\ShiftsFilter;
use Engelsystem\ShiftsFilterRenderer;
use Engelsystem\ValidationResult;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

/**
 * Text for Angeltype related links.
 *
 * @return string
 */
function angeltypes_title()
{
    return __('Angeltypes');
}

/**
 * Route angeltype actions.
 *
 * @return array
 */
function angeltypes_controller()
{
    $action = strip_request_item('action', 'list');

    switch ($action) {
        case 'view':
            return angeltype_controller();
        case 'edit':
            return angeltype_edit_controller();
        case 'delete':
            return angeltype_delete_controller();
        case 'about':
            return angeltypes_about_controller();
        case 'list':
        default:
            return angeltypes_list_controller();
    }
}

/**
 * Path to angeltype view.
 *
 * @param int   $angeltype_id AngelType id
 * @param array $params additional params
 * @return string
 */
function angeltype_link($angeltype_id, $params = [])
{
    $params = array_merge(['action' => 'view', 'angeltype_id' => $angeltype_id], $params);
    return page_link_to('angeltypes', $params);
}

/**
 * Job description for all angeltypes (public to everyone)
 *
 * @return array
 */
function angeltypes_about_controller()
{
    $user = auth()->user();

    if ($user) {
        $angeltypes = AngelTypes_with_user($user->id);
    } else {
        $angeltypes = AngelType::all();
    }

    return [
        __('Teams/Job description'),
        AngelTypes_about_view($angeltypes, (bool)$user)
    ];
}

/**
 * Delete an Angeltype.
 *
 * @return array
 */
function angeltype_delete_controller()
{
    if (!auth()->can('admin_angel_types')) {
        throw_redirect(page_link_to('angeltypes'));
    }

    $angeltype = AngelType::findOrFail(request()->input('angeltype_id'));

    if (request()->hasPostData('delete')) {
        $angeltype->delete();
        engelsystem_log('Deleted angeltype: ' . AngelType_name_render($angeltype, true));
        success(sprintf(__('Angeltype %s deleted.'), AngelType_name_render($angeltype)));
        throw_redirect(page_link_to('angeltypes'));
    }

    return [
        sprintf(__('Delete angeltype %s'), $angeltype->name),
        AngelType_delete_view($angeltype)
    ];
}

/**
 * Change an Angeltype.
 *
 * @return array
 */
function angeltype_edit_controller()
{
    // In supporter mode only allow to modify description
    $supporter_mode = !auth()->can('admin_angel_types');
    $request = request();

    if ($request->has('angeltype_id')) {
        // Edit existing angeltype
        $angeltype = AngelType::findOrFail($request->input('angeltype_id'));

        if (!User_is_AngelType_supporter(auth()->user(), $angeltype)) {
            throw_redirect(page_link_to('angeltypes'));
        }
    } else {
        // New angeltype
        if ($supporter_mode) {
            // Supporters aren't allowed to create new angeltypes.
            throw_redirect(page_link_to('angeltypes'));
        }
        $angeltype = new AngelType();
    }

    if ($request->hasPostData('submit')) {
        $valid = true;

        if (!$supporter_mode) {
            if ($request->has('name')) {
                $result = AngelType_validate_name($request->postData('name'), $angeltype);
                $angeltype->name = $result->getValue();
                if (!$result->isValid()) {
                    $valid = false;
                    error(__('Please check the name. Maybe it already exists.'));
                }
            }

            $angeltype->restricted = $request->has('restricted');
            $angeltype->no_self_signup = $request->has('no_self_signup');
            $angeltype->show_on_dashboard = $request->has('show_on_dashboard');
            $angeltype->hide_register = $request->has('hide_register');

            $angeltype->requires_driver_license = $request->has('requires_driver_license');
        }

        $angeltype->description = strip_request_item_nl('description', $angeltype->description);

        $angeltype->contact_name = strip_request_item('contact_name', $angeltype->contact_name);
        $angeltype->contact_dect = strip_request_item('contact_dect', $angeltype->contact_dect);
        $angeltype->contact_email = strip_request_item('contact_email', $angeltype->contact_email);

        if ($valid) {
            $angeltype->save();

            success('Angel type saved.');
            engelsystem_log(
                'Saved angeltype: ' . $angeltype->name . ($angeltype->restricted ? ', restricted' : '')
                . ($angeltype->no_self_signup ? ', no_self_signup' : '')
                . ($angeltype->requires_driver_license ? ', requires driver license' : '') . ', '
                . $angeltype->contact_name . ', '
                . $angeltype->contact_dect . ', '
                . $angeltype->contact_email . ', '
                . $angeltype->show_on_dashboard . ', '
                . $angeltype->hide_register
            );
            throw_redirect(angeltype_link($angeltype->id));
        }
    }

    return [
        sprintf(__('Edit %s'), $angeltype->name),
        AngelType_edit_view($angeltype, $supporter_mode)
    ];
}

/**
 * View details of a given angeltype.
 *
 * @return array
 */
function angeltype_controller()
{
    $user = auth()->user();

    if (!auth()->can('angeltypes')) {
        throw_redirect(page_link_to('/'));
    }

    $angeltype = AngelType::findOrFail(request()->input('angeltype_id'));
    $user_angeltype = UserAngelType_by_User_and_AngelType($user->id, $angeltype);
    $members = Users_by_angeltype($angeltype);

    $days = angeltype_controller_shiftsFilterDays($angeltype);
    $shiftsFilter = angeltype_controller_shiftsFilter($angeltype, $days);

    $shiftsFilterRenderer = new ShiftsFilterRenderer($shiftsFilter);
    $shiftsFilterRenderer->enableDaySelection($days);

    $shiftCalendarRenderer = shiftCalendarRendererByShiftFilter($shiftsFilter);
    $request = request();
    $tab = 0;

    if ($request->has('shifts_filter_day')) {
        $tab = 1;
    }

    $isSupporter = !is_null($user_angeltype) && $user_angeltype['supporter'];
    return [
        sprintf(__('Team %s'), $angeltype->name),
        AngelType_view(
            $angeltype,
            $members,
            $user_angeltype,
            auth()->can('admin_user_angeltypes') || $isSupporter,
            auth()->can('admin_angel_types'),
            $isSupporter,
            $user->license,
            $user,
            $shiftsFilterRenderer,
            $shiftCalendarRenderer,
            $tab
        )
    ];
}

/**
 * On which days do shifts for this angeltype occur? Needed for shiftCalendar.
 *
 * @param AngelType $angeltype
 * @return array
 */
function angeltype_controller_shiftsFilterDays(AngelType $angeltype)
{
    $all_shifts = Shifts_by_angeltype($angeltype);
    $days = [];
    foreach ($all_shifts as $shift) {
        $day = date('Y-m-d', $shift['start']);
        if (!in_array($day, $days)) {
            $days[] = $day;
        }
    }
    sort($days);
    return $days;
}

/**
 * Sets up the shift filter for the angeltype.
 *
 * @param AngelType $angeltype
 * @param array     $days
 * @return ShiftsFilter
 */
function angeltype_controller_shiftsFilter(AngelType $angeltype, $days)
{
    $request = request();
    $shiftsFilter = new ShiftsFilter(
        auth()->can('user_shifts_admin'),
        Room_ids(),
        [$angeltype->id]
    );
    $selected_day = date('Y-m-d');
    if (!empty($days) && !in_array($selected_day, $days)) {
        $selected_day = $days[0];
    }
    if ($request->has('shifts_filter_day')) {
        $selected_day = $request->input('shifts_filter_day');
    }
    $shiftsFilter->setStartTime(parse_date('Y-m-d H:i', $selected_day . ' 00:00'));
    $shiftsFilter->setEndTime(parse_date('Y-m-d H:i', $selected_day . ' 23:59'));

    return $shiftsFilter;
}

/**
 * View a list of all angeltypes.
 *
 * @return array
 */
function angeltypes_list_controller()
{
    $user = auth()->user();

    if (!auth()->can('angeltypes')) {
        throw_redirect(page_link_to('/'));
    }

    $angeltypes = AngelTypes_with_user($user->id);

    foreach ($angeltypes as $angeltype) {
        $actions = [
            button(
                page_link_to('angeltypes', ['action' => 'view', 'angeltype_id' => $angeltype->id]),
                __('view'),
                'btn-sm'
            )
        ];

        if (auth()->can('admin_angel_types')) {
            $actions[] = button(
                page_link_to('angeltypes', ['action' => 'edit', 'angeltype_id' => $angeltype->id]),
                __('edit'),
                'btn-sm'
            );
            $actions[] = button(
                page_link_to('angeltypes', ['action' => 'delete', 'angeltype_id' => $angeltype->id]),
                __('delete'),
                'btn-sm'
            );
        }

        $angeltype->membership = AngelType_render_membership($angeltype);
        if (!empty($angeltype->user_angeltype_id)) {
            $actions[] = button(
                page_link_to(
                    'user_angeltypes',
                    ['action' => 'delete', 'user_angeltype_id' => $angeltype->user_angeltype_id]
                ),
                __('leave'),
                'btn-sm'
            );
        } else {
            $actions[] = button(
                page_link_to('user_angeltypes', ['action' => 'add', 'angeltype_id' => $angeltype->id]),
                __('join'),
                'btn-sm'
            );
        }

        $angeltype->is_restricted = $angeltype->restricted ? icon('book') : '';
        $angeltype->no_self_signup_allowed = $angeltype->no_self_signup ? '' : icon('pencil-square');

        $angeltype->name = '<a href="'
            . page_link_to('angeltypes', ['action' => 'view', 'angeltype_id' => $angeltype->id])
            . '">'
            . $angeltype->name
            . '</a>';

        $angeltype->actions = table_buttons($actions);
    }

    return [
        angeltypes_title(),
        AngelTypes_list_view($angeltypes, auth()->can('admin_angel_types'))
    ];
}

/**
 * Validates a name for angeltypes.
 * Returns ValidationResult containing validation success and validated name.
 *
 * @param string    $name Wanted name for the angeltype
 * @param AngelType $angeltype The angeltype the name is for
 *
 * @return ValidationResult result and validated name
 */
function AngelType_validate_name($name, AngelType $angeltype)
{
    $name = strip_item($name);
    if ($name == '') {
        return new ValidationResult(false, '');
    }
    if ($angeltype->id) {
        $valid = AngelType::whereName($name)
                ->where('id', '!=', $angeltype->id)
                ->count() == 0;
        return new ValidationResult($valid, $name);
    }

    $valid = AngelType::whereName($name)->count() == 0;
    return new ValidationResult($valid, $name);
}

/**
 * Returns all angeltypes and subscription state to each of them for given user.
 *
 * @param int $userId
 * @return Collection|AngelType[]
 */
function AngelTypes_with_user($userId): Collection
{
    return AngelType::query()
        ->select([
            'angel_types.*',
            'UserAngelTypes.id AS user_angeltype_id',
            'UserAngelTypes.confirm_user_id',
            'UserAngelTypes.supporter',
        ])
        ->leftJoin('UserAngelTypes', function (JoinClause $join) use ($userId) {
            $join->on('angel_types.id', 'UserAngelTypes.angeltype_id');
            $join->where('UserAngelTypes.user_id', $userId);
        })
        ->get();
}
