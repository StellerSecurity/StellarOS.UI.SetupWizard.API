import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec=importlib.util.spec_from_file_location('production_guard',Path(__file__).with_name('verify_production_dependencies.py'))
guard=importlib.util.module_from_spec(spec);spec.loader.exec_module(guard)


class ProductionInventoryTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name)
        (self.root/'vendor/composer').mkdir(parents=True)
        self.row={'name':'test/runtime','version':'1.0.0','source':{'type':'git','reference':'a'*40},'bin':['bin/runtime']}
        self.dev={'name':'laravel/pint','version':'1.0.0'}
        self.lock={'packages':[self.row],'packages-dev':[self.dev]}
        self.installed={'dev':False,'packages':[self.row]}
        (self.root/'vendor/test/runtime').mkdir(parents=True)
        self.write()

    def write(self):
        (self.root/'composer.lock').write_text(json.dumps(self.lock))
        (self.root/'vendor/composer/installed.json').write_text(json.dumps(self.installed))

    def test_stale_frontend_dependencies_rejected(self):
        (self.root/'node_modules/vite').mkdir(parents=True)
        with self.assertRaisesRegex(ValueError, 'frontend dependency'):
            guard.verify(self.root)

    def test_dangling_frontend_dependency_link_rejected(self):
        (self.root/'node_modules').symlink_to(self.root/'absent')
        with self.assertRaisesRegex(ValueError, 'frontend dependency'):
            guard.verify(self.root)

    def test_exact_production_install_passes(self): self.assertEqual(guard.verify(self.root),1)

    def test_development_flag_must_be_boolean_false(self):
        for value in (True,0,None,'false'):
            self.installed['dev']=value;self.write()
            with self.assertRaises(ValueError):guard.verify(self.root)

    def test_missing_extra_and_wrong_reference_are_rejected(self):
        for rows in ([],[self.row,self.dev],[dict(self.row,source={'type':'git','reference':'b'*40})]):
            self.installed['packages']=rows;self.write()
            with self.assertRaises(ValueError):guard.verify(self.root)

    def test_stale_development_files_are_rejected_even_if_metadata_omits_them(self):
        (self.root/'vendor/laravel/pint').mkdir(parents=True)
        with self.assertRaisesRegex(ValueError,'development package remains'):guard.verify(self.root)

    def test_unknown_bin_proxy_rejected_but_locked_runtime_bin_allowed(self):
        folder=self.root/'vendor/bin';folder.mkdir()
        (folder/'runtime').write_text('proxy')
        self.assertEqual(guard.verify(self.root),1)
        (folder/'phpunit').write_text('dev proxy')
        with self.assertRaisesRegex(ValueError,'unexpected binary'):guard.verify(self.root)

    def test_missing_runtime_directory_rejected(self):
        (self.root/'vendor/test/runtime').rmdir()
        with self.assertRaisesRegex(ValueError, 'production package directory'):guard.verify(self.root)

    def test_unregistered_vendor_and_package_directories_rejected(self):
        for relative in ('vendor/unknown/payload', 'vendor/test/extra', 'vendor/composer/extra'):
            with self.subTest(relative=relative):
                directory=self.root/relative;directory.mkdir(parents=True)
                with self.assertRaises(ValueError):guard.verify(self.root)
                directory.rmdir()
                if relative.startswith('vendor/unknown/'):(self.root/'vendor/unknown').rmdir()

    def test_symlinked_namespace_and_package_rejected(self):
        package=self.root/'vendor/test/runtime';package.rmdir()
        package.symlink_to(self.root/'vendor/composer',target_is_directory=True)
        with self.assertRaises(ValueError):guard.verify(self.root)
        package.unlink();namespace=self.root/'vendor/test';namespace.rmdir()
        namespace.symlink_to(self.root/'vendor/composer',target_is_directory=True)
        with self.assertRaises(ValueError):guard.verify(self.root)

    def test_metapackage_has_no_directory(self):
        self.row['type']='metapackage';self.row['bin']=[];self.write()
        (self.root/'vendor/test/runtime').rmdir();(self.root/'vendor/test').rmdir()
        self.assertEqual(guard.verify(self.root),1)

    def test_composer_namespace_can_contain_real_packages(self):
        self.row['name']='composer/runtime';self.write()
        (self.root/'vendor/test/runtime').rename(self.root/'vendor/composer/runtime')
        (self.root/'vendor/test').rmdir()
        (self.root/'vendor/composer/autoload_real.php').write_text('<?php // generated')
        self.assertEqual(guard.verify(self.root),1)

    def test_duplicate_inventory_rejected(self):
        self.installed['packages']=[self.row,self.row];self.write()
        with self.assertRaises(ValueError):guard.verify(self.root)

    def test_duplicate_json_key_and_linked_metadata_rejected(self):
        file=self.root/'vendor/composer/installed.json'
        file.write_text('{"dev":true,"dev":false,"packages":[]}')
        with self.assertRaisesRegex(ValueError,'duplicate JSON'):guard.verify(self.root)
        file.unlink();file.symlink_to(self.root/'composer.lock')
        with self.assertRaises(ValueError):guard.verify(self.root)


if __name__=='__main__':unittest.main()
