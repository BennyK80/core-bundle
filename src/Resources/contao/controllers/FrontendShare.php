<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2016 Leo Feyer
 *
 * @license LGPL-3.0+
 */

namespace Contao;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;


/**
 * Share a page via a social network.
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class FrontendShare extends \Frontend
{

	/**
	 * Run the controller
	 *
	 * @return Response
	 */
	public function run()
	{
		switch (\Input::get('p'))
		{
			case 'facebook':
				$query  = '?u=' . rawurlencode(\Input::get('u', true));
				$query .= '&t=' . rawurlencode(\Input::get('t', true));
				$query .= '&display=popup';
				$query .= '&redirect_uri=http%3A%2F%2Fwww.facebook.com';

				return new RedirectResponse('https://www.facebook.com/sharer/sharer.php' . $query);
				break;

			case 'twitter':
				$query  = '?url=' . rawurlencode(\Input::get('u', true));
				$query .= '&text=' . rawurlencode(\Input::get('t', true));

				return new RedirectResponse('https://twitter.com/share' . $query);
				break;

			case 'gplus':
				$query  = '?url=' . rawurlencode(\Input::get('u', true));

				return new RedirectResponse('https://plus.google.com/share' . $query);
				break;
		}

		return new RedirectResponse('../');
	}
}
