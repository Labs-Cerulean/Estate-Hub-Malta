"""
PRA Construction - Modular Authentication System
Scalable for multi-company, role-based access
"""
import streamlit as st
import streamlit_authenticator as stauth
import yaml
import os
from typing import Dict, Optional, Any
from dataclasses import dataclass

@dataclass
class User:
    """Scalable user schema with company/role"""
    username: str
    name: str
    company: str
    role: str  # admin, manager, viewer, site_supervisor
    email: Optional[str] = None
    phone: Optional[str] = None
    active: bool = True

class AuthManager:
    """Modular authentication manager"""
    
    def __init__(self, config_file: str = "config.yaml"):
        self.config_file = config_file
        self.authenticator = None
        self.users: Dict[str, User] = {}
        self._load_config()
    
    def _load_config(self):
        """Load users from YAML config"""
        try:
            if os.path.exists(self.config_file):
                with open(self.config_file, 'r') as f:
                    config = yaml.safe_load(f)
                self._parse_users(config['credentials']['usernames'])
                self._init_authenticator(config)
        except Exception as e:
            st.error(f"Config error: {e}")
            self._create_default_config()
    
    def _parse_users(self, usernames_dict: Dict[str, Any]):
        """Convert YAML users to User objects"""
        for username, data in usernames_dict.items():
            self.users[username] = User(
                username=username,
                name=data['name'],
                company=data.get('company', 'PRA Construction'),
                role=data['role'],
                email=data.get('email'),
                phone=data.get('phone'),
                active=data.get('active', True)
            )
    
    def _init_authenticator(self, config: Dict):
        """Initialize Streamlit Authenticator"""
        credentials = {'usernames': {k: {'name': v.name, 'password': v.password} 
                                   for k, v in self.users.items()}}
        cookie_config = config['cookie']
        
        self.authenticator = stauth.Authenticate(
            credentials,
            cookie_config['name'],
            cookie_config['key'],
            cookie_config['expiry_days'],
            config['preauthorized']
        )
    
    def _create_default_config(self):
        """Create default config.yaml"""
        default_config = {
            'credentials': {
                'usernames': {
                    'admin': {
                        'name': 'PRA Admin',
                        'password': stauth.Hasher(['Pra2026!']).generate()[0],
                        'company': 'PRA Construction',
                        'role': 'admin',
                        'email': 'admin@pra.mt',
                        'phone': '99999999',
                        'active': True
                    },
                    'manager': {
                        'name': 'Site Manager',
                        'password': stauth.Hasher(['Site2026!']).generate()[0],
                        'company': 'PRA Construction',
                        'role': 'manager',
                        'email': 'manager@pra.mt',
                        'phone': '99999998',
                        'active': True
                    },
                    'viewer': {
                        'name': 'Viewer',
                        'password': stauth.Hasher(['View2026!']).generate()[0],
                        'company': 'PRA Construction', 
                        'role': 'viewer',
                        'email': 'viewer@pra.mt',
                        'phone': '99999997',
                        'active': True
                    }
                }
            },
            'cookie': {
                'name': 'pra_mobilization',
                'key': 'pra_cookie_key_2026_super_secure_random_key_change_me',
                'expiry_days': 30,
            },
            'preauthorized': None
        }
        
        with open(self.config_file, 'w') as f:
            yaml.dump(default_config, f)
        st.success(f"Created {self.config_file}. Update passwords and restart!")
    
    def login_ui(self) -> bool:
        """Display login UI and handle
