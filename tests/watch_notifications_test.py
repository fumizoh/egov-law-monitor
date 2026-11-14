"""Offline regressions of unchanged Python notification selection."""
import importlib.util
from pathlib import Path
import sys
import types
import unittest
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[1]


def load(name, path):
    spec = importlib.util.spec_from_file_location(name, ROOT / path)
    module = importlib.util.module_from_spec(spec)
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


class NotificationsTest(unittest.TestCase):
    def test_current_preferences_and_updates_only(self):
        names = ('sources', 'pipeline', 'storage', 'notification', 'notification.service',
                 'wordpress', 'wordpress.client', 'wordpress.service', 'watch', 'watch.email', 'watch.mailer')
        modules = {name: types.ModuleType(name) for name in names}
        for name, module in modules.items():
            if '.' in name:
                parent, child = name.rsplit('.', 1)
                setattr(modules[parent], child, module)
        modules['sources'].egov = Mock()
        modules['storage'].load_law_summaries = Mock(return_value={})
        client = modules['wordpress.client']
        client.get_watch_settings = Mock()
        email = modules['watch.email']
        email.build_subject = Mock(return_value='subject')
        email.build_body = Mock(return_value='body')
        email.build_html = Mock(return_value='html')
        mailer = modules['watch.mailer']
        mailer.send_email = Mock()
        with patch.dict(sys.modules, modules):
            load('models', 'src/models.py')
            service = load('watch.service', 'src/watch/service.py')
            modules['watch'].service = service
            monitor = load('toggle_test_monitor', 'src/monitor.py')
            old = {'law_id': 'old', 'law_name': '民法（過去分）'}
            current = {'law_id': 'current', 'law_name': '民法'}
            other = {'law_id': 'other', 'law_name': '商法'}
            for plan in ('free', 'standard'):
                client.get_watch_settings.return_value = [{'user_id': 10, 'email': 'test@example.test',
                    'plan': plan, 'notifications': False, 'watches': [{'keyword': '民法'}]}]
                self.assertFalse(service.get_watch_users()[0].notifications)
                result = monitor.send_watch_notifications([old], None, '20261113')
                self.assertEqual(result.sent_count, 0)
                mailer.send_email.assert_not_called()
                client.get_watch_settings.return_value[0]['notifications'] = True
                result = monitor.send_watch_notifications([current, other], None, '20261114')
                self.assertEqual(result.sent_count, 1)
                notifications = email.build_subject.call_args.args[0]
                self.assertEqual([n.law['law_id'] for n in notifications], ['current'])
                mailer.send_email.assert_called_once()
                mailer.send_email.reset_mock()


if __name__ == '__main__':
    unittest.main()
