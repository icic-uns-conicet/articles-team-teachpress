import { __ } from '@wordpress/i18n';
import {
    useBlockProps,
    InspectorControls
} from '@wordpress/block-editor';
import {
    PanelBody,
    SelectControl,
    TextControl,
    Button,
    Spinner,
    Notice
} from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

/**
 * Hook personalizado de debounce
 * Implementación propia para evitar dependencia de @wordpress/compose
 */
function useDebounce(value, delay) {
    const [debouncedValue, setDebouncedValue] = useState(value);

    useEffect(() => {
        const handler = setTimeout(() => {
            setDebouncedValue(value);
        }, delay);

        return () => {
            clearTimeout(handler);
        };
    }, [value, delay]);

    return debouncedValue;
}

export default function Edit({ attributes, setAttributes }) {
    const { selectedPublicationIds } = attributes;

    // Estado local para el editor (no se guarda en el bloque)
    const [members, setMembers] = useState([]);
    const [selectedMemberId, setSelectedMemberId] = useState(0);
    const [searchQuery, setSearchQuery] = useState('');
    const [searchResults, setSearchResults] = useState([]);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState('');

    const debouncedSearch = useDebounce(searchQuery, 500);

    // Cargar miembros al montar el componente
    useEffect(() => {
        apiFetch({ path: '/openalex/v1/members' })
            .then(setMembers)
            .catch(err => setError('Error al cargar miembros: ' + err.message));
    }, []);

    // Buscar publicaciones cuando cambia la búsqueda
    
    useEffect(() => {
        if (!selectedMemberId || !debouncedSearch || debouncedSearch.length < 2) {
            setSearchResults([]);
            return;
        }

        setIsLoading(true);
        setError('');

        apiFetch({
            path: `/openalex/v1/publications/${selectedMemberId}?search=${encodeURIComponent(debouncedSearch)}`
        })
            .then(results => {
                setSearchResults(results);
                setIsLoading(false);
            })
            .catch(err => {
                setError('Error al buscar: ' + err.message);
                setIsLoading(false);
            });
    }, [selectedMemberId, debouncedSearch]);

// Buscar publicaciones cuando cambia la búsqueda
/*
useEffect(() => {
    console.log('🔍 useEffect ejecutado');
    console.log('  - selectedMemberId:', selectedMemberId);
    console.log('  - debouncedSearch:', debouncedSearch);
    console.log('  - debouncedSearch.length:', debouncedSearch?.length);
    
    if (!selectedMemberId) {
        console.log('  ❌ No hay selectedMemberId');
        setSearchResults([]);
        return;
    }
    
    if (!debouncedSearch) {
        console.log('  ❌ No hay debouncedSearch');
        setSearchResults([]);
        return;
    }
    
    if (debouncedSearch.length < 2) {
        console.log('  ❌ debouncedSearch es muy corto (< 2 caracteres)');
        setSearchResults([]);
        return;
    }

    console.log('  ✅ Todas las condiciones pasadas, haciendo fetch...');
    setIsLoading(true);
    setError('');
    
    const path = `/openalex/v1/publications/${selectedMemberId}?search=${encodeURIComponent(debouncedSearch)}`;
    console.log('  📡 Llamando a:', path);
    
    apiFetch({ path: path })
        .then(results => {
            console.log('  ✅ Respuesta recibida:', results);
            setSearchResults(results);
            setIsLoading(false);
        })
        .catch(err => {
            console.error('  ❌ Error en fetch:', err);
            setError('Error al buscar: ' + err.message);
            setIsLoading(false);
        });
}, [selectedMemberId, debouncedSearch]);*/

    const addPublication = (pub) => {
        if (!selectedPublicationIds.includes(pub.pub_id)) {
            setAttributes({
                selectedPublicationIds: [...selectedPublicationIds, pub.pub_id]
            });
        }
    };

    const removePublication = (pubId) => {
        setAttributes({
            selectedPublicationIds: selectedPublicationIds.filter(id => id !== pubId)
        });
    };

    // Invalidate server-side cached HTML when selected IDs change
    useEffect(() => {
        // run only in editor (user is authenticated) and when selection is present
        if (!selectedPublicationIds || selectedPublicationIds.length === 0) return;

        // fire-and-forget POST to clear cache for these IDs
        apiFetch({
            path: '/openalex/v1/publications-cache/clear',
            method: 'POST',
            data: { ids: selectedPublicationIds }
        }).catch(() => {
            // don't surface editor errors; cache invalidation is best-effort
        });
    }, [selectedPublicationIds]);

    const blockProps = useBlockProps({
        className: 'openalex-publications-selector-block'
    });

    return (
        <>
            <InspectorControls>
                <PanelBody title={__('Configuración', 'openalex-team')}>
                    <SelectControl
                        label={__('Miembro del equipo (para buscar)', 'openalex-team')}
                        value={selectedMemberId}
                        options={[
                            { label: __('Seleccionar miembro...', 'openalex-team'), value: 0 },
                            ...members.map(m => ({
                                label: m.title,
                                value: m.id
                            }))
                        ]}
                        onChange={(value) => setSelectedMemberId(parseInt(value))}
                        help={__('Selecciona un miembro para buscar sus publicaciones. Puedes cambiar de miembro después sin perder las publicaciones ya seleccionadas.', 'openalex-team')}
                    />
                </PanelBody>
            </InspectorControls>

            <div {...blockProps}>
                <h3>{__('Publicaciones OpenAlex Seleccionadas', 'openalex-team')}</h3>

                {!selectedMemberId && (
                    <Notice status="info" isDismissible={false}>
                        {__('Selecciona un miembro del equipo en el panel lateral para comenzar a buscar publicaciones.', 'openalex-team')}
                    </Notice>
                )}

                {selectedMemberId > 0 && (
                    <>
                        <TextControl
                            label={__('Buscar publicaciones por título', 'openalex-team')}
                            value={searchQuery}
                            onChange={setSearchQuery}
                            placeholder={__('Escribe al menos 2 caracteres...', 'openalex-team')}
                        />

                        {isLoading && <Spinner />}

                        {error && (
                            <Notice status="error" isDismissible={false}>
                                {error}
                            </Notice>
                        )}

                        {searchResults.length > 0 && (
                            <div className="search-results">
                                <h4>{__('Resultados de búsqueda', 'openalex-team')}</h4>
                                <ul>
                                    {searchResults.map(pub => (
                                        <li key={pub.pub_id}>
                                            <div className="pub-info">
                                                <strong>{pub.title}</strong>
                                                <span className="pub-meta">
                                                    ({pub.year}) - {pub.type}
                                                </span>
                                            </div>
                                            <Button
                                                isPrimary
                                                onClick={() => addPublication(pub)}
                                                disabled={selectedPublicationIds.includes(pub.pub_id)}
                                            >
                                                {selectedPublicationIds.includes(pub.pub_id)
                                                    ? __('Agregada', 'openalex-team')
                                                    : __('Agregar', 'openalex-team')}
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {debouncedSearch && debouncedSearch.length >= 2 && searchResults.length === 0 && !isLoading && (
                            <p style={{color: '#666', fontStyle: 'italic'}}>
                                {__('No se encontraron publicaciones con ese título.', 'openalex-team')}
                            </p> 
                        )}

                        {selectedPublicationIds.length > 0 && (
                            <div className="selected-publications">
                                <h4>
                                    {__('Publicaciones seleccionadas', 'openalex-team')}
                                    ({selectedPublicationIds.length})
                                </h4>
                                <p className="components-base-control__help">
                                    {__('Estas publicaciones se mostrarán en el frontend al guardar la página.', 'openalex-team')}
                                </p>
                                <ul>
                                    {selectedPublicationIds.map(pubId => (
                                        <li key={pubId}>
                                            <div className="pub-info">
                                                <strong>ID: {pubId}</strong>
                                            </div>
                                            <Button
                                                isDestructive
                                                isSmall
                                                onClick={() => removePublication(pubId)}
                                            >
                                                {__('Eliminar', 'openalex-team')}
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </>
                )}
            </div>
        </>
    );
}