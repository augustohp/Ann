<?php

/**
 * Artificial Neural Network - Version 2.2
 *
 * For updates and changes visit the project page at http://ann.thwien.de/
 *
 *
 *
 * <b>LICENCE</b>
 *
 * The BSD 2-Clause License
 *
 * http://opensource.org/licenses/bsd-license.php
 *
 * Copyright (c) 2002, Eddy Young
 * Copyright (c) 2007 - 2011, Thomas Wien
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 * 1. Redistributions of source code must retain the above copyright
 * notice, this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 * notice, this list of conditions and the following disclaimer in the
 * documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS
 * FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT,
 * INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN
 * ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 *
 * @author Eddy Young <jeyoung_at_priscimon_dot_com>
 * @author Thomas Wien <info_at_thwien_dot_de>
 * @version ANN Version 1.0 by Eddy Young
 * @version ANN Version 2.2 by Thomas Wien
 * @copyright Copyright (c) 2002 by Eddy Young
 * @copyright Copyright (c) 2007-2011 by Thomas Wien
 * @package ANN
 */

namespace ANN;

/**
 * @package ANN
 * @access private
 */

class Maths
{
	/**
	 * @param float $floatValue
	 * @return float (between near 0 and near 1)
	 */
	
	public static function sigmoid($floatValue)
	{
	  return 1 / (1 + exp(-1 * $floatValue));
	}
	
	/**
	 * @param float $floatValue
	 * @return integer (0 or 1)
	 */
	
	public static function threshold($floatValue)
	{
	  return ($floatValue > 0.5) ? 1 : 0;
	}
	
	/**
	 * @return float
	 */
	
	public static function randomDelta()
	{
	  return mt_rand(-999, 999) / 1000;
	}

	/**
	 * @return float
	 */
	
	public static function randomWeight()
	{
	  return mt_rand(-2000, 2000) / 1000;
	}
}
