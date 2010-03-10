<?php defined('SYSPATH') OR die('No direct access allowed.');

// common 
class Exception_Thumbler extends Kohana_Exception {};

// Max width or height of image were be reached.
class Exception_Thumbler_Max extends Exception_Thumbler {};
class Exception_Thumbler_Max_Width extends Exception_Thumbler_Max {};
class Exception_Thumbler_Max_Height extends Exception_Thumbler_Max {};
class Exception_Thumbler_Max_Square extends Exception_Thumbler_Max {};

// File not accessable
class Exception_Thumbler_File extends Exception_Thumbler {};
class Exception_Thumbler_File_Found extends Exception_Thumbler_File {};
class Exception_Thumbler_File_Read extends Exception_Thumbler_File {};
class Exception_Thumbler_File_Error extends Exception_Thumbler_File {};
class Exception_Thumbler_File_Write extends Exception_Thumbler_File {};


class Thumbler
{
	// owner of this
	protected $owner_id;
	protected $owner_object_name;
	
	// Name of image. Each model may have many Thumblers.
	protected $name;
	
	// cache of config files. May be changed per-object
	protected $config;
	
	// cache for config
	protected $path;
	protected $path_url;
	
	/**
	 * Create thumbler object from Model object.
	 * @param ORM $owner
	 * @param string $name Name of the picture. Not linked with fields in model.
	 */
	public function __construct(ORM $owner, $name = 'default')
	{
		$this->set_owner($owner);
		
		$this->name = $name;
		
		$config = Kohana::config('thumbler');
		
		$this->path = realpath($config['path']);
		
		$this->path_url = realpath($config['path_url']); 
		
		$this->config = $config['models'][$this->owner_object_name];
	}
	
	public function set_owner(ORM $owner)
	{
		$this->owner_id = $owner->id;
		
		$this->owner_object_name = $owner->object_name();
	}
	
	/**
	 * Returns link to array with config for current image
	 * There no way to change config, except change values inplace  
	 */
	public function & get_config($size = FALSE)
	{
		if ($size === FALSE) 
		{
			return $this->config['thumbs'][$this->name];
		}
		else
		{
			return $this->config['thumbs'][$this->name]['sizes'][$size];
		}
	}
	
	/**
	 * 
	 * @param string $path_to_original path to image file 
	 */
	public function save_image($input_file)
	{
		// Try to regenerate 
		if ($input_file === TRUE)
		{
			// We need biggest picture.
			$file = $this->path(FALSE, TRUE); 
		}
		else 
		{
			// Otherviwe we add new Image
			$file = $input_file;
		}
		
		if ( ! is_file($file))
		{
			throw new Exception_Thumbler_File_Found('File :file not found',
				array(':file' => Kohana::debug_path($file)));
		}
		
		if ( ! is_readable($file))
		{
			throw new Exception_Thumbler_File_Read('File :file can not be readed',
				array(':file' => Kohana::debug_path($file)));
		}
		
		try
		{
			$original = Image::factory($file);
		}
		catch (Exception $e)
		{
			$original = NULL;
		}
		
		if ( ! $original)
		{
			throw new Exception_Thumbler_File_Error('File :file contain some errors and can not be loaded',
				array(':file' => Kohana::debug_path($file)));
		}
		
		if ( ! empty($this->config['max_width']) AND ($original->width > $this->config['max_width'])) 
		{
			throw new Exception_Thumbler_Max_Width('Image width is too big');
		}
		
		if ( ! empty($this->config['max_height']) AND ($original->height > $this->config['max_height']))
		{
			throw new Exception_Thumbler_Max_Width('Image height is too big');
		}
		
		if ( ! empty($this->config['max_square']) AND ($original->width * $original->height > $this->config['max_square'])) 
		{
			throw new Exception_Thumbler_Max_Width('Image width is too big');
		}
		
		$dir = $this->path . DIRECTORY_SEPARATOR . $this->directory(TRUE);
		
		if ( ! is_dir($dir))
		{
			try
			{
				if ( ! mkdir($dir, 0777, TRUE))
				{ 
					throw new Exception();
				}
			}
			catch (Exception $e)
			{
				throw new Exception_Thumbler_File_Write('Can not create :dir',
					array(':dir' => Kohana::debug_path($dir)));
			}
		}
		
		if ( ! is_writable($dir))
		{
			throw new Exception_Thumbler_File_Write('Can not write in :dir',
				array(':dir' => Kohana::debug_path($dir)));
		}
		
		// Config 
		$image_config = $this->get_config();
		
		if ($input_file !== TRUE)
		{
			// process main image only for new image
			$this->_process_image_size($original, $image_config['default_size']);
		}
		
		$image_sizes = array_keys($image_config['sizes']);
		
		// Exclude default size from size list becouse it already processed
		unset($image_sizes[array_search($image_config['default_size'], $image_sizes)]);
		
		foreach ($image_sizes AS $size)
		{
			$this->_process_image_size(clone $original, $size);
		}
	}
	
	/*
	 * Makes single thumb by given Image and size name
	 */
	protected function _process_image_size(Image $image, $size)
	{
		$path = $this->path($size, TRUE);
		
		$config = $this->get_config($size);
		
		if ( ! empty($config['delete']) AND $config['delete'] == 'delete')
		{
			if (file_exists($path))
			{
				try
				{
					unlink($path);
				}
				catch (Exception $e) {};
			}
			return $image;
		}
		
		$image->resize($config['width'], $config['height'], $config['master']);
		
		if ($config['strict_size'] AND $config['width'] AND $config['height'])
		{
			$image->padding($config['width'], $config['height'], $config['align_x'], $config['align_y'], $config['back_color']);
		}
		
		$image->save($path, empty($config['quality']) ? Kohana::config('thumbler.default_quality', 85) : $config['quality']);
		
		return $image;
	}
	
	public function delete_image()
	{
		// Config 
		$image_config = $this->get_config();
		
		$image_sizes = array_keys($image_config['sizes']);
		
		foreach ($image_sizes AS $size)
		{
			$path = $this->path($size, TRUE);
			
			if (file_exists($path))
			{
				try
				{
					unlink($path);
				}
				catch (Exception $e) {};
			}
		}
	}
	
	protected function directory($in_file_system = FALSE)
	{
		$folder = $this->owner_id;
		
		if ($this->config['objects_per_folder'])
		{
			$folder = floor($this->owner_id / $this->config['objects_per_folder']);
		}
		
		if ($this->config['limit_folders'])
		{
			$folder = $folder % $this->config['limit_folders']; 
		}
		
		return $this->owner_object_name . ($in_file_system ? DIRECTORY_SEPARATOR : '/') . $folder . ($in_file_system ? DIRECTORY_SEPARATOR : '/');
	}
	
	/**
	 * 
	 * @param string $size Name of "size" group in config. 
	 * @param boolean $in_file_system If THUE, path in server file system will be returned 
	 */
	public function path($size = FALSE, $in_file_system = FALSE)
	{
		$image_config = $this->get_config();
		
		if ($size === FALSE)
		{
			$size = $image_config['default_size'];
		}
		
		return ($in_file_system ? $this->path : $this->path_url) . DIRECTORY_SEPARATOR . $this->directory() . $this->owner_id . '_' . $this->name . '_' . $size . '.' 
			. $image_config['sizes'][$size]['format'];
	}
	 
} // End of Thumbler